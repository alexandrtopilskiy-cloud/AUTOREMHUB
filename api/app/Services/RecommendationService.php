<?php

declare(strict_types=1);

namespace App\Services;

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
    public function __construct(
        private readonly RepairRequestService $requests,
        private readonly ApiPresenter $presenter,
        private readonly DocumentLockService $locks,
    ) {}

    public function forVehicle(User $user, string $vehicleId): array
    {
        return Recommendation::forUser($user)
            ->whereIn('vehicle_id', Vehicle::query()->select('id')->where('uuid', $vehicleId))
            ->with(['items', 'vehicle'])
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

            $display = $this->presenter->document($request, $user);
            $doc = Recommendation::query()->create([
                'tenant_id'                 => $request->tenant_id,
                'vehicle_id'                => $request->vehicle_id,
                'vin'                       => $request->vehicleRecord->vin,
                'customer_organization_id'  => $request->customer_organization_id,
                'source_request_id'         => $request->id,
                'created_by'                => $user->id,
            ]);

            $doc->forceFill([
                'number' => 'REC-' . str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT)
            ])->save();

            $actorLabel = match ($user->role) {
                UserRole::Manager   => 'У',
                UserRole::Station   => 'С',
                default             => 'З'
            };
            $actor = "{$user->display_name} ($actorLabel)";

            foreach (['work' => $works, 'part' => $parts] as $kind => $lines) {
                foreach ($lines as $line) {
                    $shown = collect($display[$kind === 'work' ? 'works' : 'parts'])->firstWhere('id', $line->uuid);
                    RecommendationItem::query()->create([
                        'recommendation_id'     => $doc->id,
                        'kind'                  => $kind,
                        'snapshot'              => $line->attributesToArray(),
                        'display'               => $shown,
                        'actor_id'              => $user->id,
                        'actor_label'           => $actor,
                    ]);
                    $line->delete();
                }
            }
            $this->requests->recommendationEstimateChanged($request, $user, false);

            return [
                'comment' => 'Позиции перенесены в рекомендации ' . $doc->number,
                'details' => [
                    'recommendationId' => $doc->uuid,
                    'selection' => $selection
                ]
            ];
        });

        return [
            'request' => $this->presenter->document($request, $user),
            'recommendation' => $this->present($doc->fresh(['items', 'vehicle']), $user)
        ];
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
            $existing = $requestId ? RepairRequest::resolveFor($user, $requestId, true) : null;
            $docs = Recommendation::forUser($user)
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
                $this->locks->assertAvailable($user, 'recommendation', $doc->uuid);
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
                    ->lockForUpdate()
                    ->get();
                if ($selected->count() !== count($selection['itemIds'])) {
                    Input::fail('Некоторые позиции уже перенесены или не принадлежат рекомендациям');
                }

                $items = $items->concat($selected);
            }

            if ($items->count() > 200) {
                Input::fail('За один раз можно перенести не более 200 позиций');
            }

            $target = $existing ?? $this->requests->createFromRecommendation($user, $first);

            if ($target->vehicle_id !== $first->vehicle_id || $target->vehicleRecord?->vin !== $first->vin) {
                Input::fail('Рекомендации можно переносить только в заявку для того же VIN');
            }

            $request = $this->requests->mutate($user, $target->uuid, $existing ? ($requestRevision ?? -1) : $target->revision, 'recommendations_restored', function (RepairRequest $request) use ($user, $items, $docs): array {
                $this->requests->flag($request, $user, 'canRestoreRecommendations');
                foreach ($items as $item) {
                    $this->restoreItem($request, $item);
                    $item->target_request_id = $request->id;
                    $item->moved_at = now();
                    $item->save();
                }

                $this->requests->recommendationEstimateChanged($request, $user, true);

                return [
                    'comment' => 'Позиции добавлены из рекомендаций',
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
                'recommendations' => $docs->map(fn ($doc) => $this->present($doc->fresh(['items', 'vehicle']), $user))->all(),
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
        $doc->loadMissing(['items', 'vehicle']);

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
            'createdAt' => $doc->created_at->toISOString(),
            'items' => $doc->items->map(fn ($item) => [
                'id' => $item->uuid,
                'kind' => $item->kind,
                'position' => $item->display,
                'actor' => $item->actor_label,
                'createdAt' => $item->created_at->toISOString(),
                'movedAt' => $item->moved_at?->toISOString(),
                'targetRequestId' => $item->target_request_id,
            ])->all()
        ];

        return $user === null ? $data : CommercialVisibility::forUser($data, $user);
    }
}
