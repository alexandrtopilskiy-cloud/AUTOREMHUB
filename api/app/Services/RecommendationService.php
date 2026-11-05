<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\UserDto;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Recommendation;
use App\Models\RecommendationItem;
use App\Models\RepairRequest;
use App\Models\RequestWork;
use App\Models\RequestPart;
use App\Models\Vehicle;
use App\Models\User;
use App\Support\ApiPresenter;
use App\Support\CommercialVisibility;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;

final class RecommendationService
{
    private const RELATIONS = ['items.sourceRequest', 'items.targetRequest', 'items.deletedBy', 'vehicle', 'customerOrganization'];

    public function __construct(
        private readonly RepairRequestService $requests,
        private readonly ApiPresenter $presenter,
        private readonly DocumentLockService $locks,
    ) {}

    public function forVehicle(User $user, string $vehicleId): array
    {
        $dto = UserDto::fromUserModel($user);

        return Recommendation::forUser($dto)
            ->whereIn('vehicle_id', Vehicle::query()->select('id')->where('uuid', $vehicleId))
            ->with(self::RELATIONS)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($doc) => $this->present($doc, $user))
            ->all();
    }

    /**
     * Все рекомендации, видимые пользователю: Заказчику — по ТС своей
     * организации, УК — по всем ТС тенанта.
     */
    public function all(User $user): array
    {
        $dto = UserDto::fromUserModel($user);

        return Recommendation::forUser(dto: $dto)
            ->with(self::RELATIONS)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($doc) => $this->present($doc, $user))
            ->all();
    }

    public function defer(User $user, string $key, int $revision, array $selection): array
    {
        Input::validate($selection, [
            'workIds' => ['present', 'array', 'list', 'max:200'],
            'workIds.*' => ['uuid', 'distinct'],
            'partIds' => ['present', 'array', 'list', 'max:200'],
            'partIds.*' => ['uuid', 'distinct']
        ]);

        if ($selection['workIds'] === [] && $selection['partIds'] === []) {
            Input::fail('Выберите хотя бы одну работу или запчасть');
        }

        $doc = null;
        $request = $this->requests->mutate($user, $key, $revision, 'recommendations_deferred', function (RepairRequest $request) use ($user, $selection, &$doc): array {
            $this->requests->flag($request, $user, 'canDeferRecommendations');
            if (!$request->vehicle_id) {
                Input::fail('Сначала выберите ТС');
            }
            $works = $request->workLines()
                ->whereIn('uuid', $selection['workIds'])
                ->get();
            $parts = $request->parts()
                ->whereIn('uuid', $selection['partIds'])
                ->get();
            if ($works->count() !== count($selection['workIds']) || $parts->count() !== count($selection['partIds'])) {
                Input::fail('Выбранная позиция уже изменена или не принадлежит заявке');
            }

            foreach ($parts as $part) {
                app(RequestPartService::class)->assertEditable($part);
            }

            // На стадии «В работе» перенос в рекомендации — изменение сметы,
            // которое СТО отправляет на согласование УК и Заказчику.
            WorkChangeService::begin($request);

            $display = $this->presenter->document($request, $user);
            $doc = $this->documentForVehicle($request, $user);
            $actor = self::actorLabel($user);

            $deferredLineIds = [];
            $deferredItemIds = [];
            foreach (['work' => $works, 'part' => $parts] as $kind => $lines) {
                foreach ($lines as $line) {
                    $shown = collect($display[$kind === 'work' ? 'works' : 'parts'])->firstWhere('id', $line->uuid);
                    $deferredLineIds[] = $line->uuid;
                    $deferredItemIds[] = RecommendationItem::query()->create([
                        'recommendation_id'     => $doc->id,
                        'source_request_id'     => $request->id,
                        'kind'                  => $kind,
                        'snapshot'              => $line->attributesToArray(),
                        'display'               => $shown,
                        'actor_id'              => $user->id,
                        'actor_label'           => $actor,
                    ])->uuid;
                    $line->delete();
                }
            }
            // Ревизия документа не меняется: добавление строк не мешает
            // переносу уже выбранных позиций (они проверяются по id).
            WorkChangeService::recordDeferred($request, $deferredLineIds, $deferredItemIds, (string) $doc->number);
            $this->requests->recommendationEstimateChanged($request, $user, false);

            return [
                'comment' => 'Позиции добавлены в рекомендации ' . $doc->number,
                'details' => [
                    'recommendationId' => $doc->uuid,
                    'selection' => $selection
                ]
            ];
        });

        return [
            'request' => $this->presenter->document($request, $user),
            'recommendation' => $this->present($doc->fresh(self::RELATIONS), $user)
        ];
    }

    /**
     * Один документ рекомендаций на ТС: при первом переносе он создаётся,
     * последующие переносы из любых ЗР добавляют в него новые строки.
     */
    private function documentForVehicle(RepairRequest $request, User $user): Recommendation
    {
        $attributes = ['tenant_id' => $request->tenant_id, 'vehicle_id' => $request->vehicle_id];
        $doc = Recommendation::query()->where($attributes)->lockForUpdate()->first();

        if ($doc === null) {
            // Параллельный первый перенос по тому же ТС: уникальный индекс
            // (tenant_id, vehicle_id) не даст создать второй документ.
            DB::statement(
                'INSERT INTO recommendations (uuid, tenant_id, vehicle_id, customer_organization_id, source_request_id, created_by, vin, revision, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, now(), now())
                 ON CONFLICT (tenant_id, vehicle_id) DO NOTHING',
                [
                    (string) \Illuminate\Support\Str::uuid(),
                    $request->tenant_id,
                    $request->vehicle_id,
                    $request->customer_organization_id,
                    $request->id,
                    $user->id,
                    $request->vehicleRecord->vin,
                ],
            );
            $doc = Recommendation::query()->where($attributes)->lockForUpdate()->firstOrFail();
        }

        if ($doc->number === null) {
            $doc->number = 'REC-' . str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT);
        }
        $doc->forceFill([
            'customer_organization_id' => $request->customer_organization_id,
            'source_request_id'        => $request->id,
            'vin'                      => $request->vehicleRecord->vin,
        ])->save();

        return $doc;
    }

    private static function actorLabel(User $user): string
    {
        $label = match ($user->role) {
            UserRole::Manager   => 'У',
            UserRole::Station   => 'С',
            default             => 'З'
        };

        return "{$user->display_name} ($label)";
    }

    /**
     * Удаление выбранных строк из Рекомендаций ТС (Заказчик и УК). Строка
     * пропадает из документа, в истории остаются автор и время удаления.
     */
    public function deleteItems(User $user, string $id, int $revision, array $itemIds): array
    {
        Input::validate(['itemIds' => $itemIds], [
            'itemIds' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'itemIds.*' => ['required', 'uuid', 'distinct'],
        ]);

        if (!in_array($user->role, [UserRole::Customer, UserRole::Manager], true)) {
            throw new ApiException(403, 'FORBIDDEN', 'Удалять рекомендации могут Заказчик и УК');
        }

        return DB::transaction(function () use ($user, $id, $revision, $itemIds): array {
            $dto = UserDto::fromUserModel($user);
            $doc = Recommendation::forUser($dto)->where('uuid', $id)->lockForUpdate()->firstOrFail();
            $this->locks->assertAvailable($dto, 'recommendation', $doc->uuid);

            if ($doc->revision !== $revision) {
                throw new ApiException(409, 'REVISION_CONFLICT', 'Рекомендации уже изменены. Обновите документ.');
            }

            $items = $doc->items()
                ->whereIn('uuid', $itemIds)
                ->whereNull('moved_at')
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get();
            if ($items->count() !== count($itemIds)) {
                Input::fail('Некоторые позиции уже перенесены, удалены или не принадлежат рекомендациям');
            }

            RecommendationItem::query()
                ->whereIn('id', $items->pluck('id'))
                ->update(['deleted_at' => now(), 'deleted_by' => $user->id]);
            $doc->revision++;
            $doc->save();

            return ['recommendation' => $this->present($doc->fresh(self::RELATIONS), $user)];
        }, 3);
    }

    public function restore(User $user, string $id, int $revision, array $itemIds, ?string $requestId, ?int $requestRevision): array
    {
        $result = $this->restoreMany(
            user: $user,
            documents: [['id' => $id, 'revision' => $revision, 'itemIds' => $itemIds]],
            requestId: $requestId,
            requestRevision: $requestRevision,
        );

        $result['recommendation'] = $result['recommendations'][0];

        return $result;
    }

    /** Перенос из нескольких пакетов REC выполняется одной транзакцией и одной ревизией заявки. */
    public function restoreMany(User $user, array $documents, ?string $requestId, ?int $requestRevision): array
    {
        Input::validate(['documents' => $documents], [
            'documents' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'documents.*' => ['array:id,revision,itemIds'],
            'documents.*.id' => ['required', 'uuid', 'distinct'],
            'documents.*.revision' => ['required', 'integer', 'min:0'],
            'documents.*.itemIds' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'documents.*.itemIds.*' => ['required', 'uuid', 'distinct'],
        ]);

        return DB::transaction(function () use ($user, $documents, $requestId, $requestRevision): array {
            $dto = UserDto::fromUserModel($user);
            $existing = $requestId ? RepairRequest::resolveFor($dto, $requestId, true) : null;
            $docs = Recommendation::forUser($dto)
                ->whereIn('uuid', array_column($documents, 'id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($docs->count() !== count($documents)) {
                Input::fail('Рекомендации недоступны или уже изменены');
            }

            $selections = collect($documents)->keyBy('id');

            $items = collect();
            $first = $docs->first();

            foreach ($docs as $doc) {
                $this->locks->assertAvailable($dto, 'recommendation', $doc->uuid);
                $selection = $selections->get($doc->uuid);

                if ($doc->revision !== (int) $selection['revision']) {
                    throw new ApiException(409, 'REVISION_CONFLICT', 'Рекомендации уже изменены. Обновите документ.');
                }

                if ($doc->vehicle_id !== $first->vehicle_id || $doc->vin !== $first->vin) {
                    Input::fail('Все рекомендации должны относиться к одному VIN');
                }

                $selected = $doc->items()
                    ->whereIn('uuid', $selection['itemIds'])
                    ->whereNull('moved_at')
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->get();
                if ($selected->count() !== count($selection['itemIds'])) {
                    Input::fail('Некоторые позиции уже перенесены, удалены или не принадлежат рекомендациям');
                }

                $items = $items->concat($selected);
            }

            if ($items->count() > 200) {
                Input::fail('За один раз можно перенести не более 200 позиций');
            }

            // Новую заявку из рекомендаций создаёт только Заказчик; УК и СТО
            // переносят позиции в ЗР, из которой открыли рекомендации.
            if ($existing === null && $user->role !== UserRole::Customer) {
                throw new ApiException(403, 'FORBIDDEN', 'Создать заявку из рекомендаций может только Заказчик');
            }

            $target = $existing ?? $this->requests->createFromRecommendation($user, $first);

            if ($target->vehicle_id !== $first->vehicle_id || $target->vehicleRecord?->vin !== $first->vin) {
                Input::fail('Рекомендации можно переносить только в заявку для того же VIN');
            }

            $request = $this->requests->mutate($user, $target->uuid, $existing ? ($requestRevision ?? -1) : $target->revision, 'recommendations_restored', function (RepairRequest $request) use ($user, $items, $docs): array {
                $this->requests->flag($request, $user, 'canRestoreRecommendations');
                // На стадии «В работе» добавленные позиции — изменение сметы,
                // которое СТО отправляет на согласование УК и Заказчику.
                WorkChangeService::begin($request);
                foreach ($items as $item) {
                    $this->restoreItem($request, $item);
                    $item->target_request_id = $request->id;
                    $item->moved_at = now();
                    $item->save();
                }

                WorkChangeService::recordRestored($request, $items->pluck('uuid')->all());
                $this->requests->recommendationEstimateChanged($request, $user, true);

                return [
                    'comment' => 'Позиции добавлены из рекомендаций ' . $docs->pluck('number')->implode(', '),
                    'details' => [
                        'recommendationIds' => $docs->pluck('uuid')->all(),
                        'items' => $items->pluck('uuid')->all(),
                    ],
                    'after' => fn ($event) => $this->requests->notifyRecommendationReview($request, $user->role->value, $event->id)
                ];
            });

            foreach ($docs as $doc) {
                $doc->revision++;
                $doc->save();
            }

            return [
                'request' => $this->presenter->document($request, $user),
                'recommendations' => $docs->map(fn ($doc) => $this->present($doc->fresh(self::RELATIONS), $user))->all(),
            ];
        }, 3);
    }

    private function restoreItem(RepairRequest $request, RecommendationItem $item): void
    {
        $workFields = [
            'work_type_id',
            'time_norm_id',
            'name',
            'quantity',
            'price',
            'hourly_rate',
            'norm_hours',
            'actual_hours',
            'line_amount',
            'norm_is_manual',
            'norm_override_reason',
            'norm_selection_scope',
            'work_code_snapshot',
            'norm_id_snapshot',
            'is_maintenance',
        ];

        $partFields = [
            'catalog_part_id',
            'supplier_id',
            'name',
            'article',
            'unit',
            'quantity',
            'price',
            'manufacturer_snapshot',
            'purchase_price',
        ];
        // JSON-снимок может происходить из более ранней редакции. В SQL идут только колонки модели.
        $fields = $item->kind === 'work' ? $workFields : $partFields;
        $attributes = array_intersect_key($item->snapshot, array_flip($fields));
        $attributes['repair_request_id'] = $request->id;
        $attributes['name'] ??= $item->display['name'] ?? '';

        if ($attributes['name'] === '' || !is_numeric($attributes['quantity'] ?? null)) {
            Input::fail('В рекомендации отсутствует наименование или количество: ' . $item->uuid);
        }

        if ($item->kind === 'work') {
            RequestWork::query()->create($attributes);
        } else {
            if (!is_numeric($attributes['price'] ?? null)) {
                Input::fail('В рекомендации отсутствует цена запчасти: ' . $item->uuid);
            }
            $attributes['status'] = 'proposed';
            $attributes['approved_version'] = null;
            $attributes['returned_quantity'] = '0';
            $attributes['manufacturer_snapshot'] ??= $item->display['manufacturer'] ?? '';
            RequestPart::query()->create($attributes);
        }
    }

    public function present(Recommendation $doc, ?User $user = null): array
    {
        $doc->loadMissing(self::RELATIONS);

        $all = $doc->items;
        // В документе остаются только невыполненные строки: перенесённые в ЗР
        // и удалённые исчезают из таблиц и видны лишь в истории.
        $open = $all->filter(fn (RecommendationItem $item) => $item->isOpen())->values();
        $sources = $all
            ->map(fn (RecommendationItem $item) => $item->sourceRequest?->number)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $data = [
            'id' => $doc->uuid,
            'systemId' => $doc->id,
            'number' => $doc->number,
            'revision' => $doc->revision,
            'vin' => $doc->vin,
            'make' => $doc->vehicle?->make ?? '',
            'model' => $doc->vehicle?->model ?? '',
            'plate' => $doc->vehicle?->plate ?? '',
            'vehicleId' => $doc->vehicle?->uuid ?? '',
            'company' => $user?->role === UserRole::Station ? '' : ($doc->customerOrganization?->name ?? ''),
            'createdAt' => $doc->created_at->toISOString(),
            'updatedAt' => $doc->updated_at?->toISOString(),
            'openCount' => $open->count(),
            'movedCount' => $all->whereNotNull('moved_at')->count(),
            'deletedCount' => $all->whereNull('moved_at')->whereNotNull('deleted_at')->count(),
            'openWorks' => $open->where('kind', 'work')->count(),
            'openParts' => $open->where('kind', 'part')->count(),
            'lastAddedAt' => $all->max('created_at')?->toISOString(),
            'sourceRequests' => $sources,
            'items' => $open->map(fn (RecommendationItem $item) => [
                'id' => $item->uuid,
                'kind' => $item->kind,
                'position' => $item->display,
                'actor' => $item->actor_label,
                'createdAt' => $item->created_at->toISOString(),
                'sourceRequestId' => $item->sourceRequest?->uuid,
                'sourceRequestNumber' => $item->sourceRequest?->number,
            ])->all(),
            'history' => $all->map(fn (RecommendationItem $item) => [
                'id' => $item->uuid,
                'name' => (string) ($item->display['name'] ?? ''),
                'actor' => $item->actor_label,
                'createdAt' => $item->created_at->toISOString(),
                'sourceRequestNumber' => $item->sourceRequest?->number,
                'movedAt' => $item->moved_at?->toISOString(),
                'targetRequestNumber' => $item->targetRequest?->number,
                'deletedAt' => $item->deleted_at?->toISOString(),
                'deletedBy' => $item->deletedBy === null ? null : self::actorLabel($item->deletedBy),
            ])->values()->all(),
        ];

        return $user === null ? $data : CommercialVisibility::forUser($data, $user);
    }
}
