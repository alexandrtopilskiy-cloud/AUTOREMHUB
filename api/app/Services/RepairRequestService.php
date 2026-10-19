<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\AttachmentPurpose;
use App\Enums\InvoiceStatus;
use App\Enums\PersonKind;
use App\Enums\RepairStage;
use App\Enums\RequestPartStatus;
use App\Enums\TransitionAction;
use App\Enums\UserRole;
use App\Enums\WorkOrderItemKind;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\RepairRequest;
use App\Models\RequestApproval;
use App\Models\RequestEvent;
use App\Models\RequestNotification;
use App\Models\RequestPart;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\TimeNorm;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAcceptanceAct;
use App\Models\VehicleContact;
use App\Models\VehicleServiceRecord;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkType;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class RepairRequestService
{
    private const DB_DEADLOCK_RETRIES = 3;
    private const QUANTITY_SCALE = 3;

    private const REVIEW_INITIAL = 'initial';
    private const REVIEW_STATION_CHANGES = 'station_changes';
    private const REVIEW_MANAGER_CHANGES = 'manager_changes';
    private const REVIEW_STATION_DECLINED = 'station_declined';
    private const REVIEW_CUSTOMER_SLOT = 'customer_slot';

    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly Workflow $workflow,
        private readonly RepairRequestCalculator $repairRequestCalculator,
        private readonly TimeNormSelectionService $timeNormSelection,
        private readonly WorkCostService $workCost,
        private readonly ApiPresenter $apiPresenter,
        private readonly DocumentLockService $documentLocks,
    ) {
    }

    /* ─────────────────── Создание ─────────────────── */

    public function create(User $user): RepairRequest
    {
        if ($user->role !== UserRole::Customer) {
            throw new ApiException(403, 'FORBIDDEN', 'Заявку создаёт Заказчик');
        }

        return DB::transaction(function () use ($user): RepairRequest {
            $uuid = (string) Str::uuid();

            $request = RepairRequest::query()->create([
                'uuid'                     => $uuid,
                'public_id'                => $uuid,
                'tenant_id'                => $user->tenantId(),
                'customer_organization_id' => $user->organization_id,
                'created_by'               => $user->id,
                'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
                'works'                    => [],
                'history'                  => [],
                'stage'                    => RepairStage::Draft,
                'revision'                 => 0,
                'review_context'           => self::REVIEW_INITIAL,
                'review_baseline_version'  => 0,
            ]);

            RequestStatusPeriod::query()->create([
                'repair_request_id' => $request->id,
                'stage'             => RepairStage::Draft->value,
                'entered_at'        => now(),
                'actor_id'          => $user->id,
            ]);

            $this->event($request, $user, 'create', 'Создан пустой черновик');

            return $request->refresh();
        });
    }

    /**
     * Удаляет только собственные черновики, которые не передавались по маршруту.
     * Операция атомарна для всего выбранного набора.
     *
     * @param array<int, string> $ids
     *
     * @return array<int, string>
     */
    public function deleteDrafts(User $user, array $ids): array
    {
        $validated = Input::validate(['ids' => $ids], [
            'ids'   => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        /** @var array<int, array{disk: string, key: string}> $files */
        $files = [];

        $deleted = DB::transaction(function () use ($user, $validated, &$files): array {
            $documents = RepairRequest::query()
                ->with(['attachments', 'chat'])
                ->where('tenant_id', $user->tenantId())
                ->where('created_by', $user->id)
                ->where('stage', RepairStage::Draft->value)
                // stage=0 недостаточно: возвращённый из маршрута документ
                // имеет периоды других этапов и остаётся в истории.
                ->whereDoesntHave(
                    'periods',
                    fn ($query) => $query->where('stage', '<>', RepairStage::Draft->value),
                )
                ->whereIn('uuid', $validated['ids'])
                ->lockForUpdate()
                ->get();

            if ($documents->count() !== count($validated['ids'])) {
                throw new ApiException(
                    403,
                    'DRAFT_DELETE_FORBIDDEN',
                    'Можно удалять только собственные черновики, '
                        . 'не переданные по маршруту',
                );
            }

            foreach ($documents as $document) {
                if ($document->chat !== null) {
                    throw new ApiException(
                        409,
                        'CHAT_HISTORY_EXISTS',
                        'Черновик с созданным чатом нельзя удалить: история хранится вместе с заявкой',
                    );
                }

                $this->documentLocks->assertAvailable($user, 'request', $document->uuid);

                foreach ($document->attachments as $attachment) {
                    $files[] = [
                        'disk' => $attachment->disk,
                        'key'  => $attachment->object_key,
                    ];
                }

                $this->documentLocks->releaseResource($user, 'request', $document->uuid);
                $document->attachments()->delete();
                $document->delete();
            }

            return $documents->pluck('uuid')->values()->all();
        });

        foreach ($files as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['key']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $deleted;
    }

    /* ─────────────────── Права и мутации ─────────────────── */

    public function flag(RepairRequest $request, User $user, string $flag): void
    {
        $permissions = Workflow::permissions($request, $user);

        if (!($permissions[$flag] ?? false)) {
            throw new ApiException(403, 'FORBIDDEN', 'Действие недоступно вашей роли на текущем этапе');
        }
    }

    /**
     * @param callable(RepairRequest): array<string, mixed> $change
     *
     * @throws Throwable
     */
    public function mutate(
        User $user,
        string $key,
        int $revision,
        string $action,
        callable $change,
    ): RepairRequest {
        return DB::transaction(function () use ($user, $key, $revision, $action, $change): RepairRequest {
            $request = RepairRequest::resolveFor($user, $key, true);
            $this->documentLocks->assertAvailable($user, 'request', $request->uuid);

            if ($request->revision !== $revision) {
                throw new ApiException(409, 'REVISION_CONFLICT', 'Заявка уже изменена другим пользователем');
            }

            $result = $change($request) ?? [];

            $request->revision++;
            $request->save();

            $event = $this->event(
                $request,
                $user,
                $action,
                $result['comment'] ?? $action,
                $result['details'] ?? [],
            );

            if (isset($result['after'])) {
                ($result['after'])($event);
            }

            return $request->refresh();
        }, self::DB_DEADLOCK_RETRIES);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function event(
        RepairRequest $request,
        User $user,
        string $action,
        string $comment,
        array $details = [],
    ): RequestEvent {
        return RequestEvent::query()->create([
            'repair_request_id' => $request->id,
            'actor_id'          => $user->id,
            'actor_label'       => $user->roleLabel(),
            'action'            => $action,
            'comment'           => $comment,
            'details'           => $details,
            'stage'             => $request->stage->value,
            'revision'          => $request->revision,
            'occurred_at'       => now(),
        ]);
    }

    public function createFromRecommendation(User $user, \App\Models\Recommendation $doc): RepairRequest
    {
        $vehicle = Vehicle::query()->where('tenant_id', $user->tenantId())->findOrFail($doc->vehicle_id);
        $uuid = (string) Str::uuid();
        $data = array_fill_keys(Workflow::TEXT_FIELDS, '');
        $data['recommendationVehicleLocked'] = true;
        $source = RepairRequest::query()->find($doc->source_request_id);
        // СТО не меняет пробег. При создании из рекомендаций сохраняем
        // зафиксированные в исходной заявке сведения, а не придумываем новые.
        if ($user->role !== UserRole::Customer && $source !== null) {
            $data['mileage'] = (string) ($source->data['mileage'] ?? '');
            $data['laborRateType'] = (string) ($source->data['laborRateType'] ?? 'general');
        }
        $previous = $this->vehicleService->previousMileageDetails($vehicle, $user);
        $data['laborRateType'] = (string) ($source?->data['laborRateType'] ?? 'general');
        if ($user->role !== UserRole::Customer && $previous['mileage'] !== null) {
            $data['mileage'] = (string) max((int) ($data['mileage'] ?: 0), $previous['mileage']);
        }
        $request = RepairRequest::query()->create([
            'uuid' => $uuid, 'public_id' => $uuid, 'tenant_id' => $user->tenantId(),
            'customer_organization_id' => $doc->customer_organization_id,
            'vehicle_id' => $vehicle->id, 'created_by' => $user->id,
            'assigned_station_id' => $user->role === UserRole::Station ? Station::query()->where('organization_id', $user->organization_id)->value('id') : null,
            'data' => $data, 'works' => [], 'history' => [],
            'previous_service_mileage' => $previous['mileage'], 'previous_service_at' => $previous['recordedAt'],
            'stage' => RepairStage::Draft, 'revision' => 0, 'review_context' => self::REVIEW_INITIAL,
            'review_baseline_version' => 0,
        ]);
        RequestStatusPeriod::query()->create(['repair_request_id' => $request->id, 'stage' => 0, 'entered_at' => now(), 'actor_id' => $user->id]);
        $this->event($request, $user, 'create', 'Черновик создан из рекомендаций ' . $doc->number);
        return $request;
    }

    public function recommendationEstimateChanged(RepairRequest $request, User $user, bool $restored): void
    {
        if ($restored && $request->stage === RepairStage::Draft) {
            $request->data = array_replace($request->data, ['recommendationVehicleLocked' => true]);
        }
        $request->estimate_version++;
        RequestPart::query()->where('repair_request_id', $request->id)->update(['approved_version' => null]);
        $this->recalculatePlannedCompletion($request);
        if ($restored && $user->role === UserRole::Customer && $request->stage === RepairStage::CustomerApproval) {
            $this->moveToStage($request, $user, RepairStage::ManagerRouting);
            $this->captureReviewBaseline($request, self::REVIEW_INITIAL);
        }
    }

    public function notifyRecommendationReview(RepairRequest $request, User $user, RequestEvent $event): void
    {
        if ($user->role === UserRole::Customer && $request->stage === RepairStage::ManagerRouting) {
            $this->notifyUsers($this->managerUsers($request), $request, $event, 'manager_review_required', 'Добавлены рекомендации', 'Заказчик добавил работы или запчасти из рекомендаций. Требуется согласование сметы.');
        }
    }

    /* ─────────────────── Обновление полей ─────────────────── */

    /**
     * @param array<string, mixed> $patch
     *
     * @throws Throwable
     */
    public function update(User $user, string $key, int $revision, array $patch): RepairRequest
    {
        if ($patch === []) {
            Input::fail('Передайте хотя бы одно изменяемое поле в patch');
        }

        return $this->mutate($user, $key, $revision, 'patch', function (RepairRequest $request) use ($user, $patch): array {
            $this->assertPatchFieldsAllowed($request, $user, $patch);

            $rules = $this->updateRules();
            Input::validate($patch, $rules);

            $this->validateLocalTimeFields($patch);
            $this->validateMileage($patch);

            $this->applyVehicleChange($request, $user, $patch);
            $this->applyContactChange($request, $patch);
            $this->applyDriverChange($request, $patch);
            $this->applyStationChange($request, $patch);
            $this->applyReasonChange($request, $user, $patch);
            $this->applyReasonSubtypeChange($request, $patch);

            $request->data = array_replace(
                $request->data,
                array_intersect_key($patch, array_flip(Workflow::TEXT_FIELDS)),
            );

            if (array_key_exists('slot', $patch)) {
                $request->data = array_replace(
                    $request->data,
                    ['stationSlot' => ''],
                );
            }

            $this->assertMileageNotLessThanPrevious($request);

            if (array_key_exists('parts', $patch)) {
                app(RequestPartService::class)->replace($request, $patch['parts']);
            }

            if (array_key_exists('works', $patch) || array_key_exists('laborRateType', $patch)) {
                $works = $patch['works'] ?? app(\App\Support\ApiPresenter::class)->document($request, $user)['works'];
                $this->replaceWorks($request, $works);
            }
            if (array_key_exists('works', $patch) || array_key_exists('laborRateType', $patch) || array_key_exists('parts', $patch)) {
                $request->estimate_version++;

                RequestPart::query()
                    ->where('repair_request_id', $request->id)
                    ->update(['approved_version' => null]);
            }

            if (
                array_key_exists('works', $patch)
                || array_key_exists('slot', $patch)
                || array_key_exists('stationSlot', $patch)
                || array_key_exists('stationId', $patch)
            ) {
                $this->recalculatePlannedCompletion($request);
            }

            return ['comment' => 'Сохранены поля: ' . implode(', ', array_keys($patch))];
        });
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function assertPatchFieldsAllowed(RepairRequest $request, User $user, array $patch): void
    {
        if (array_diff(array_keys($patch), Workflow::FIELDS)) {
            Input::fail('Обнаружены поля, которые нельзя изменять');
        }

        $allowed = Workflow::permissions($request, $user)['editableFields'];

        if (!$allowed || array_diff(array_keys($patch), $allowed)) {
            throw new ApiException(403, 'FORBIDDEN', 'Поля на текущем этапе недоступны');
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function updateRules(): array
    {
        $rules = [];

        foreach (Workflow::TEXT_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'string', 'max:10000'];
        }

        foreach (
            ['vehicleId', 'contactId', 'driverId', 'stationId', 'reasonId', 'reasonSubtypeId'] as $field
        ) {
            $rules[$field] = Input::uuidOrEmpty();
        }

        $rules['laborRateType'] = ['sometimes', 'in:general,official,other'];

        $rules['works'] = ['sometimes', 'array', 'list', 'max:200'];
        $rules['parts'] = ['sometimes', 'array', 'list', 'max:200'];

        return $rules;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function validateLocalTimeFields(array $patch): void
    {
        $minimum = now()->addMinutes(30)->startOfMinute();
        $minuteRemainder = (int) $minimum->format('i') % 30;

        if ($minuteRemainder !== 0) {
            $minimum = $minimum->addMinutes(30 - $minuteRemainder);
        }

        foreach (['slot', 'stationSlot'] as $field) {
            if (isset($patch[$field])) {
                $date = Input::localTime($patch[$field]);

                if ($date !== null && $date->lt($minimum)) {
                    Input::fail(
                        'Дата и время должны быть не ранее текущего времени '
                            . 'плюс 30 минут',
                        $field,
                    );
                }

                if ($date !== null) {
                    $localTime = $date
                        ->setTimezone((string) config('sto.service_timezone'))
                        ->format('H:i');
                    $visitTimeStart = (string) config('sto.visit_time_start');
                    $visitTimeEnd = (string) config('sto.visit_time_end');

                    if ($localTime < $visitTimeStart || $localTime > $visitTimeEnd) {
                        Input::fail(
                            'Время визита должно быть с '
                                . $visitTimeStart
                                . ' до '
                                . $visitTimeEnd,
                            $field,
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function validateMileage(array $patch): void
    {
        if (
            isset($patch['mileage'])
            && $patch['mileage'] !== ''
            && !preg_match('/^\d{1,9}$/', (string) $patch['mileage'])
        ) {
            Input::fail('Пробег должен быть целым числом', 'mileage');
        }
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyVehicleChange(RepairRequest $request, User $user, array $patch): void
    {
        if (!array_key_exists('vehicleId', $patch)) {
            return;
        }

        $vehicle = $patch['vehicleId'] === ''
            ? null
            : Vehicle::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('organization_id', $request->customer_organization_id)
            ->where('uuid', $patch['vehicleId'])
            ->first();

        if ($patch['vehicleId'] !== '' && !$vehicle) {
            Input::fail('Автомобиль не входит в перечень организации', 'vehicleId');
        }

        if ($request->vehicle_id !== $vehicle?->id) {
            $request->vehicle_id = $vehicle?->id;
            $previous = $vehicle !== null
                ? $this->vehicleService->previousMileageDetails($vehicle, $user, $request->id)
                : ['mileage' => null, 'recordedAt' => null];

            $request->previous_service_mileage = $previous['mileage'];
            $request->previous_service_at = $previous['recordedAt'];
            $request->contact_id = null;
            $request->contact_snapshot = null;
            $request->driver_id = null;
            $request->driver_snapshot = null;
            $request->data = array_replace($request->data, ['contact' => '']);
        }
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyContactChange(RepairRequest $request, array $patch): void
    {
        if (!array_key_exists('contactId', $patch)) {
            return;
        }

        if ($patch['contactId'] === '') {
            $request->contact_id = null;
            $request->contact_snapshot = null;
            $request->data = array_replace($request->data, ['contact' => '']);

            return;
        }

        if ($request->vehicle_id === null) {
            Input::fail('Сначала выберите автомобиль', 'vehicleId');
        }

        $contact = VehicleContact::query()
            ->where('vehicle_id', $request->vehicle_id)
            ->where('kind', PersonKind::Contact->value)
            ->where('uuid', $patch['contactId'])
            ->where('active', true)
            ->first();

        if ($contact === null) {
            Input::fail('Контактное лицо не относится к выбранному автомобилю', 'contactId');
        }

        $request->contact_id = $contact->id;
        $request->contact_snapshot = $contact->snapshot();
        $request->data = array_replace(
            $request->data,
            ['contact' => $contact->fullName() . ' · ' . $contact->phone],
        );
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyDriverChange(RepairRequest $request, array $patch): void
    {
        if (!array_key_exists('driverId', $patch)) {
            return;
        }

        if ($patch['driverId'] === '') {
            $request->driver_id = null;
            $request->driver_snapshot = null;

            return;
        }

        if ($request->vehicle_id === null) {
            Input::fail('Сначала выберите автомобиль', 'vehicleId');
        }

        $driver = VehicleContact::query()
            ->where('vehicle_id', $request->vehicle_id)
            ->where('kind', PersonKind::Driver->value)
            ->where('uuid', $patch['driverId'])
            ->where('active', true)
            ->first();

        if ($driver === null) {
            Input::fail('Водитель не относится к выбранному автомобилю', 'driverId');
        }

        $request->driver_id = $driver->id;
        $request->driver_snapshot = $driver->snapshot();
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyStationChange(RepairRequest $request, array $patch): void
    {
        if (!array_key_exists('stationId', $patch)) {
            return;
        }

        $station = $patch['stationId'] === ''
            ? null
            : Station::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('active', true)
            ->where('uuid', $patch['stationId'])
            ->first();

        if ($patch['stationId'] !== '' && !$station) {
            Input::fail('Выберите доступную СТО');
        }

        $request->assigned_station_id = $station?->id;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyReasonChange(
        RepairRequest $request,
        User $user,
        array $patch,
    ): void {
        if (!array_key_exists('reasonId', $patch)) {
            return;
        }

        $reason = $patch['reasonId'] === ''
            ? null
            : ServiceReason::query()
            ->where('tenant_id', $request->tenant_id)
            ->whereNull('parent_id')
            ->where('active', true)
            ->when(
                $user->role === UserRole::Customer,
                fn ($query) => $query->whereIn('code', ['maintenance', 'diagnostics']),
            )
            ->where('uuid', $patch['reasonId'])
            ->first();

        if ($patch['reasonId'] !== '' && !$reason) {
            Input::fail('Выберите причину из справочника');
        }

        if ($request->reason_id !== $reason?->id) {
            $request->reason_subtype_id = null;
        }

        $request->reason_id = $reason?->id;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyReasonSubtypeChange(RepairRequest $request, array $patch): void
    {
        if (!array_key_exists('reasonSubtypeId', $patch)) {
            return;
        }

        $subtype = $patch['reasonSubtypeId'] === ''
            ? null
            : ServiceReason::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('parent_id', $request->reason_id)
            ->where('active', true)
            ->where('uuid', $patch['reasonSubtypeId'])
            ->first();

        if ($patch['reasonSubtypeId'] !== '' && (!$request->reason_id || !$subtype)) {
            Input::fail('Подвид не относится к выбранной причине');
        }

        $request->reason_subtype_id = $subtype?->id;
    }

    private function assertMileageNotLessThanPrevious(RepairRequest $request): void
    {
        $mileage = $request->data['mileage'] ?? '';

        if (
            $mileage !== ''
            && $request->previous_service_mileage !== null
            && (int) $mileage < $request->previous_service_mileage
        ) {
            Input::fail('Пробег меньше пробега на прошлом ТО');
        }
    }

    /* ─────────────────── Работы ─────────────────── */

    /**
     * @param array<int, mixed> $works
     */
    private function replaceWorks(RepairRequest $request, array $works): void
    {
        $works = array_values(array_filter(
            $works,
            static fn (mixed $work): bool => !is_array($work)
                || !empty($work['workTypeId'])
                || trim((string) ($work['name'] ?? '')) !== '',
        ));
        $rows = [];
        $vehicle = $request->vehicleRecord()->first();
        $hourlyRate = $vehicle?->laborRate((string) ($request->data['laborRateType'] ?? 'general'));

        foreach ($works as $work) {
            if (!is_array($work)) {
                Input::fail('Некорректная строка работ');
            }

            foreach (['normHours', 'actualHours'] as $key) {
                if (($work[$key] ?? null) === '') {
                    $work[$key] = null;
                }
            }

            Input::validate($work, [
                'id'          => ['sometimes', 'string'],
                'name'        => ['present', 'string', 'max:500'],
                'type'        => ['sometimes', 'in:Работа'],
                'workTypeId'  => Input::uuidOrEmpty(),
                'timeNormId'  => Input::uuidOrEmpty(),
                'qty'         => ['required', 'numeric', 'min:0', 'max:1000000', Input::numeric(3)],
                'price'       => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
                'hourlyRate'  => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
                'normHours'   => ['nullable', 'numeric', 'min:0.001', 'max:100000'],
                'actualHours' => ['nullable', 'numeric', 'min:0', 'max:100000'],
                'normIsManual' => ['sometimes', 'boolean'],
                'normOverrideReason' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'workCode' => ['sometimes', 'nullable', 'string', 'max:20'],
                'normId' => ['sometimes', 'nullable', 'string', 'max:30'],
                'normSelectionScope' => ['sometimes', 'nullable', 'string', 'max:40'],
                'lineAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ]);

            $type = $this->resolveWorkType($request, $work);
            $selection = $type !== null && $vehicle !== null
                ? $this->timeNormSelection->select($type, $vehicle)
                : [
                    'status' => TimeNormSelectionService::STATUS_MISSING,
                    'norm' => null,
                    'scope' => null,
                    'conflicts' => [],
                ];

            if ($selection['status'] === TimeNormSelectionService::STATUS_CONFLICT) {
                throw new ApiException(
                    422,
                    'TIME_NORM_CONFLICT',
                    'Найдено несколько одинаково приоритетных '
                    . 'норм времени',
                    [
                        'works' => implode(', ', array_map(
                            static fn (TimeNorm $norm): string => $norm->norm_id,
                            $selection['conflicts'],
                        )),
                    ],
                );
            }

            /** @var TimeNorm|null $norm */
            $norm = $selection['norm'];
            if (!empty($work['timeNormId']) && $norm?->uuid !== $work['timeNormId']) {
                Input::fail(
                    'Выбранная норма не является наиболее узкой '
                    . 'для автомобиля',
                    'works',
                );
            }

            $providedNorm = $work['normHours'] ?? null;
            $reason = trim((string) ($work['normOverrideReason'] ?? ''));
            $isManual = (bool) ($work['normIsManual'] ?? false)
                || ($providedNorm !== null && (
                    $norm?->hours === null
                    || bccomp((string) $providedNorm, (string) $norm->hours, 3) !== 0
                ));

            if ($isManual && ($providedNorm === null || $reason === '')) {
                Input::fail(
                    'Для ручной нормы укажите положительное значение '
                    . 'и причину корректировки',
                    'works',
                );
            }

            $normHours = $isManual ? $providedNorm : $norm?->hours;
            $calculation = $normHours !== null && $hourlyRate !== null
                ? $this->workCost->calculate(
                    (string) $normHours,
                    (string) $hourlyRate,
                    (string) $work['qty'],
                )
                : null;

            $rows[] = [
                'repair_request_id' => $request->id,
                'name'              => $type?->name ?? trim((string) $work['name']),
                'work_code_snapshot' => $type?->code ?? '',
                'quantity'          => $work['qty'],
                'price'             => $calculation['unitPrice'] ?? null,
                'line_amount'       => $calculation['amount'] ?? null,
                'hourly_rate'       => $hourlyRate,
                'work_type_id'      => $type?->id,
                'time_norm_id'      => $norm?->id,
                'norm_id_snapshot'  => $norm?->norm_id,
                'norm_selection_scope' => $selection['scope'],
                'norm_override_reason' => $isManual ? $reason : null,
                'norm_is_manual'    => $isManual,
                'norm_hours'        => $normHours,
                'actual_hours'      => $work['actualHours'] ?? null,
                'is_maintenance'    => $type?->is_maintenance ?? false,
            ];
        }

        RequestWork::query()->where('repair_request_id', $request->id)->delete();

        foreach ($rows as $row) {
            RequestWork::query()->create($row);
        }
    }

    /**
     * @param array<string, mixed> $work
     */
    private function resolveWorkType(RepairRequest $request, array $work): ?WorkType
    {
        if (empty($work['workTypeId'])) {
            return null;
        }

        $type = WorkType::query()
            ->where('uuid', $work['workTypeId'])
            ->where('tenant_id', $request->tenant_id)
            ->where('active', true)
            ->first();

        if (!$type) {
            Input::fail('Вид работы недоступен');
        }

        return $type;
    }

    /**
     * Расчётное завершение = время визита + 30 минут на приёмку + сумма
     * норм времени с учётом количества работ.
     */
    private function recalculatePlannedCompletion(RepairRequest $request): void
    {
        $stationSlot = (string) ($request->data['stationSlot'] ?? '');
        $desiredSlot = (string) ($request->data['slot'] ?? '');
        $slot = $stationSlot !== '' ? $stationSlot : $desiredSlot;

        if ($slot === '') {
            $request->planned_completion_at = null;

            return;
        }

        if ($request->workLines()->whereNull('norm_hours')->exists()) {
            $request->planned_completion_at = null;

            return;
        }

        $start = Input::localTime($slot);

        if ($start === null) {
            $request->planned_completion_at = null;

            return;
        }

        $normSeconds = $request->workLines()
            ->get()
            ->sum(static fn (RequestWork $work): int => (int) round(
                (float) ($work->norm_hours ?? 0)
                * (float) $work->quantity
                * 3600,
            ));

        $completion = $start
            ->addMinutes(30)
            ->addSeconds($normSeconds);

        $station = $request->assigned_station_id !== null
            ? Station::query()->find($request->assigned_station_id)
            : null;

        $request->planned_completion_at = $station === null
            ? $completion
            : $this->applyStationSchedule($completion, $station);
    }

    /**
     * Переносит расчётное завершение в допустимое рабочее окно СТО.
     * Если результат достиг времени закрытия, используется начало следующего
     * рабочего дня. Выходные дни пропускаются.
     */
    private function applyStationSchedule(
        CarbonImmutable $completion,
        Station $station,
    ): CarbonImmutable {
        $timezone = (string) config('sto.service_timezone');
        $local = $completion->setTimezone($timezone);
        $workingDays = array_values(array_unique(array_map(
            static fn (mixed $day): int => (int) $day,
            $station->working_days ?? range(1, 7),
        )));
        $workdayStart = $this->minutesFromTime(
            (string) ($station->workday_start ?? '10:00'),
        );
        $workdayEnd = $this->minutesFromTime(
            (string) ($station->workday_end ?? '20:00'),
        );

        if (!in_array($local->dayOfWeekIso, $workingDays, true)) {
            return $this->nextStationWorkday(
                $local,
                $workingDays,
                $workdayStart,
            )->utc();
        }

        $minutes = ($local->hour * 60) + $local->minute;

        if ($minutes < $workdayStart) {
            return $this->atMinuteOfDay($local, $workdayStart)->utc();
        }

        if ($minutes >= $workdayEnd) {
            return $this->nextStationWorkday(
                $local,
                $workingDays,
                $workdayStart,
            )->utc();
        }

        return $local->utc();
    }

    /**
     * @param array<int, int> $workingDays
     */
    private function nextStationWorkday(
        CarbonImmutable $date,
        array $workingDays,
        int $workdayStart,
    ): CarbonImmutable {
        $candidate = $date->addDay()->startOfDay();

        for ($attempt = 0; $attempt < 7; $attempt++) {
            if (in_array($candidate->dayOfWeekIso, $workingDays, true)) {
                return $this->atMinuteOfDay($candidate, $workdayStart);
            }

            $candidate = $candidate->addDay();
        }

        return $this->atMinuteOfDay($candidate, $workdayStart);
    }

    private function atMinuteOfDay(
        CarbonImmutable $date,
        int $minuteOfDay,
    ): CarbonImmutable {
        return $date->setTime(
            intdiv($minuteOfDay, 60),
            $minuteOfDay % 60,
        );
    }

    private function minutesFromTime(string $value): int
    {
        return ((int) substr($value, 0, 2) * 60)
            + (int) substr($value, 3, 2);
    }

    /* ─────────────────── Вложения ─────────────────── */

    /**
     * @param array<int, string> $ids
     *
     * @return array<int, Attachment>
     */
    public function attachmentIds(
        RepairRequest $request,
        array $ids,
        AttachmentPurpose|string|null $purpose = null,
        bool $current = false,
    ): array {
        if (count($ids) !== count(array_unique($ids))) {
            Input::fail('Вложения повторяются');
        }

        if (!$ids) {
            return [];
        }

        $query = Attachment::query()
            ->where('repair_request_id', $request->id)
            ->whereIn('uuid', $ids);

        if ($purpose !== null) {
            $query->where(
                'purpose',
                $purpose instanceof AttachmentPurpose ? $purpose->value : $purpose,
            );
        }

        if ($current) {
            $periodId = $request->periods()
                ->whereNull('exited_at')
                ->firstOrFail()
                ->id;

            $query->where('request_status_period_id', $periodId)
                ->whereNull('request_event_id');
        }

        $rows = $query->get();

        if ($rows->count() !== count($ids)) {
            Input::fail('Вложение недоступно или относится к другому этапу');
        }

        return $rows->all();
    }

    /* ─────────────────── Переходы этапов ─────────────────── */

    /**
     * Сохраняет решение СТО по назначенному времени визита.
     *
     * @param array<string, mixed> $body
     *
     * @throws Throwable
     */
    public function stationDecision(
        User $user,
        string $key,
        int $revision,
        array $body,
    ): RepairRequest {
        $validated = Input::validate($body, [
            'decision' => [
                'required',
                'string',
                'in:' . implode(',', [
                    ApprovalDecision::Confirmed->value,
                    ApprovalDecision::Rescheduled->value,
                    ApprovalDecision::Declined->value,
                ]),
            ],
            'comment'     => ['sometimes', 'nullable', 'string', 'max:10000'],
            'stationSlot' => ['sometimes', 'string'],
        ]);

        $comment = trim((string) ($validated['comment'] ?? ''));
        $decision = ApprovalDecision::from($validated['decision']);
        if ($decision === ApprovalDecision::Declined && $comment === '') {
            Input::fail('Укажите причину отказа от выполнения заявки', 'comment');
        }

        return $this->mutate(
            $user,
            $key,
            $revision,
            'station_' . $decision->value,
            function (RepairRequest $request) use ($user, $decision, $comment, $validated): array {
                $this->flag($request, $user, 'canStationDecide');

                if ($decision === ApprovalDecision::Declined) {
                    $declined = $request->data['declinedStationIds'] ?? [];
                    $declined[] = $request->stationRecord?->uuid;
                    $request->data = array_replace($request->data, ['declinedStationIds' => array_values(array_unique(array_filter($declined)))]);
                }
                $from = $request->stage;
                $desiredSlot = (string) ($request->data['slot'] ?? '');

                if ($decision === ApprovalDecision::Confirmed) {
                    $request->data = array_replace(
                        $request->data,
                        ['stationSlot' => $desiredSlot],
                    );
                    $this->recalculatePlannedCompletion($request);
                }

                if ($decision === ApprovalDecision::Rescheduled) {
                    $stationSlot = (string) ($validated['stationSlot'] ?? '');

                    if ($stationSlot === '' || $stationSlot === $desiredSlot) {
                        Input::fail('Выберите другое время визита', 'stationSlot');
                    }

                    $this->validateLocalTimeFields(['stationSlot' => $stationSlot]);
                    $request->data = array_replace(
                        $request->data,
                        ['stationSlot' => $stationSlot],
                    );
                    $this->recalculatePlannedCompletion($request);
                }

                $changes = $this->estimateChanges($request);
                $estimateChanged = $request->estimate_version
                    !== $request->review_baseline_version;

                if (
                    $decision !== ApprovalDecision::Declined
                    && !$estimateChanged
                ) {
                    $this->validateManagerReviewStage($request);
                }

                $to = match (true) {
                    $decision === ApprovalDecision::Declined => RepairStage::ManagerRouting,
                    $estimateChanged => RepairStage::ManagerRouting,
                    default => RepairStage::CustomerApproval,
                };

                $snapshot = $this->buildTransitionSnapshot($request);

                if ($to === RepairStage::ManagerRouting) {
                    $this->captureReviewBaseline(
                        $request,
                        $estimateChanged
                            ? self::REVIEW_STATION_CHANGES
                            : self::REVIEW_STATION_DECLINED,
                    );
                } else {
                    $request->review_context = self::REVIEW_INITIAL;
                }

                $this->moveToStage($request, $user, $to);
                $request->data = array_replace($request->data, ['routeComment' => '']);

                $decisionLabel = $decision->label();

                return [
                    'comment' => $decisionLabel . ($comment !== '' ? ': ' . $comment : ''),
                    'details' => [
                        'decision' => $decision->value,
                        'from'     => $from->value,
                        'to'       => $to->value,
                        'changes'  => $changes,
                    ],
                    'after' => function (RequestEvent $event) use (
                        $request,
                        $user,
                        $from,
                        $decision,
                        $comment,
                        $snapshot,
                        $to,
                        $changes,
                    ): void {
                        $this->createApproval(
                            $request,
                            $event,
                            $user,
                            $from,
                            $decision,
                            $comment,
                            $snapshot,
                        );

                        if ($to === RepairStage::ManagerRouting) {
                            $this->notifyManagerReviewRequired(
                                $request,
                                $event,
                                $decision,
                                $changes,
                            );
                        } else {
                            $this->notifyCustomerFinalApproval($request, $event);
                        }

                        if (
                            $decision === ApprovalDecision::Rescheduled
                            && $to === RepairStage::CustomerApproval
                        ) {
                            $this->notifySlotChanged($request, $event);
                        }
                    },
                ];
            },
        );
    }

    /**
     * Сохраняет финальное решение Заказчика. Согласованная заявка переходит в
     * ожидание автомобиля, новое время возвращает её в УК.
     *
     * @param array<string, mixed> $body
     *
     * @throws Throwable
     */
    public function customerSlotDecision(
        User $user,
        string $key,
        int $revision,
        array $body,
    ): RepairRequest {
        $validated = Input::validate($body, [
            'decision' => [
                'required',
                'string',
                'in:' . implode(',', [
                    ApprovalDecision::Accepted->value,
                    ApprovalDecision::Rescheduled->value,
                ]),
            ],
            'slot'     => ['sometimes', 'string'],
            'comment'  => ['sometimes', 'string', 'max:10000'],
        ]);

        return $this->mutate(
            $user,
            $key,
            $revision,
            'customer_slot_' . ApprovalDecision::from($validated['decision'])->value,
            function (RepairRequest $request) use ($user, $validated): array {
                $this->flag($request, $user, 'canCustomerSlotDecide');

                $decision = ApprovalDecision::from($validated['decision']);
                $stationSlot = (string) ($request->data['stationSlot'] ?? '');

                if ($decision === ApprovalDecision::Accepted) {
                    if ($stationSlot === '') {
                        Input::fail('СТО не указала новое время визита');
                    }

                    $slot = $stationSlot;
                } else {
                    $slot = (string) ($validated['slot'] ?? '');

                    if ($slot === '' || $slot === $stationSlot) {
                        Input::fail('Выберите другое время визита', 'slot');
                    }

                    $this->validateLocalTimeFields(['slot' => $slot]);
                    $stationSlot = '';
                }

                $request->data = array_replace($request->data, [
                    'slot'         => $slot,
                    'stationSlot'  => $stationSlot,
                    'routeComment' => '',
                ]);
                $this->recalculatePlannedCompletion($request);
                $snapshot = $this->buildTransitionSnapshot($request);
                $from = $request->stage;

                $to = $decision === ApprovalDecision::Accepted
                    ? RepairStage::WaitingVehicle
                    : RepairStage::ManagerRouting;

                if ($decision === ApprovalDecision::Accepted) {
                    $this->approveCurrentEstimate($request);
                } else {
                    $this->captureReviewBaseline($request, self::REVIEW_CUSTOMER_SLOT);
                }

                $this->moveToStage($request, $user, $to);

                $comment = trim((string) ($validated['comment'] ?? ''));
                $label = $decision === ApprovalDecision::Accepted
                    ? 'Заказчик согласовал время СТО'
                    : 'Заказчик предложил другое время';

                return [
                    'comment' => $label . ($comment !== '' ? ': ' . $comment : ''),
                    'details' => [
                        'decision' => $decision->value,
                        'from'     => $from->value,
                        'to'       => $to->value,
                        'slot'     => $slot,
                    ],
                    'after' => function (RequestEvent $event) use (
                        $request,
                        $user,
                        $from,
                        $decision,
                        $comment,
                        $snapshot,
                        $to,
                    ): void {
                        $this->createApproval(
                            $request,
                            $event,
                            $user,
                            $from,
                            $decision,
                            $comment,
                            $snapshot,
                        );

                        if ($to === RepairStage::WaitingVehicle) {
                            $this->notifyVehicleExpected($request, $event);
                        } else {
                            $this->notifyManagerReviewRequired(
                                $request,
                                $event,
                                self::REVIEW_CUSTOMER_SLOT,
                                [],
                            );
                        }
                    },
                ];
            },
        );
    }

    /**
     * Фиксирует неизменяемый акт приёма ТС и переводит заявку в работу.
     *
     * @param array<string, mixed> $body
     *
     * @throws Throwable
     */
    public function acceptVehicle(
        User $user,
        string $key,
        int $revision,
        array $body,
    ): RepairRequest {
        $validated = Input::validate($body, [
            'mode'                  => ['required', 'string', 'in:electronic,uploaded'],
            'attachmentId'          => ['sometimes', 'nullable', 'uuid'],
            'mileage'               => ['required', 'integer', 'min:0', 'max:999999999'],
            'fuelLevel'             => ['sometimes', 'nullable', 'string', 'in:empty,quarter,half,three_quarters,full'],
            'condition'             => ['sometimes', 'nullable', 'string', 'max:10000'],
            'defectTypes'           => ['sometimes', 'array', 'list', 'max:20'],
            'defectTypes.*'         => ['required', 'string', 'in:scratch,dent,chip,glass_crack,corrosion,other'],
            'diagramMarks'          => ['sometimes', 'array', 'list', 'max:50'],
            'diagramMarks.*.zone'   => ['required', 'string', 'in:front,rear,left,right,roof,windshield,rear_glass'],
            'diagramMarks.*.type'   => ['required', 'string', 'in:scratch,dent,chip,glass_crack,corrosion,other'],
            'diagramMarks.*.comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->mutate(
            $user,
            $key,
            $revision,
            'vehicle_acceptance',
            function (RepairRequest $request) use ($user, $validated): array {
                $this->flag($request, $user, 'canAcceptVehicle');

                if ($request->acceptanceAct()->exists()) {
                    throw new ApiException(
                        409,
                        'ACCEPTANCE_ACT_LOCKED',
                        'Акт приёма уже сохранён и недоступен для изменения',
                    );
                }

                $mode = (string) $validated['mode'];
                $attachment = null;

                if ($mode === 'uploaded') {
                    $attachmentId = (string) ($validated['attachmentId'] ?? '');

                    if ($attachmentId === '') {
                        Input::fail('Прикрепите заполненный акт приёма', 'attachmentId');
                    }

                    $attachment = $this->attachmentIds(
                        $request,
                        [$attachmentId],
                        AttachmentPurpose::AcceptanceAct,
                        true,
                    )[0];
                } elseif (
                    trim((string) ($validated['condition'] ?? '')) === ''
                    || empty($validated['fuelLevel'])
                ) {
                    Input::fail(
                        'Для электронного акта укажите уровень топлива и состояние ТС',
                    );
                }

                $act = VehicleAcceptanceAct::query()->create([
                    'repair_request_id' => $request->id,
                    'station_id'        => $request->assigned_station_id,
                    'created_by'        => $user->id,
                    'attachment_id'     => $attachment?->id,
                    'number'            => 'AP-' . $request->number,
                    'mode'              => $mode,
                    'vehicle_snapshot'  => [
                        'make'  => $request->vehicleRecord?->make,
                        'model' => $request->vehicleRecord?->model,
                        'vin'   => $request->vehicleRecord?->vin,
                        'plate' => $request->vehicleRecord?->plate,
                    ],
                    'contact_snapshot'  => $request->contact_snapshot,
                    'driver_snapshot'   => $request->driver_snapshot,
                    'mileage'           => (int) $validated['mileage'],
                    'fuel_level'        => $validated['fuelLevel'] ?? null,
                    'condition'         => (string) ($validated['condition'] ?? ''),
                    'defect_types'      => $validated['defectTypes'] ?? [],
                    'diagram_marks'     => $validated['diagramMarks'] ?? [],
                    'locked_at'         => now(),
                ]);

                $from = $request->stage;
                $this->moveToStage($request, $user, RepairStage::RepairInProgress);

                return [
                    'comment' => 'Сохранён акт приёма ТС ' . $act->number . '. Заявка передана в работу',
                    'details' => [
                        'from'            => $from->value,
                        'to'              => RepairStage::RepairInProgress->value,
                        'acceptanceActId' => $act->uuid,
                    ],
                    'after' => function (RequestEvent $event) use ($request, $attachment): void {
                        if ($attachment !== null) {
                            $attachment->update(['request_event_id' => $event->id]);
                        }

                        $this->notifyWorkStarted($request, $event);
                    },
                ];
            },
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws Throwable
     */
    public function transition(
        User $user,
        string $key,
        int $revision,
        TransitionAction $transition,
        array $body,
    ): RepairRequest {
        Input::validate($body, [
            'comment'         => ['sometimes', 'string', 'max:10000'],
            'confirmDeclinedStation' => ['sometimes', 'boolean'],
            'attachmentIds'   => ['sometimes', 'array', 'list', 'max:20'],
            'attachmentIds.*' => ['required', 'uuid'],
        ]);

        $body = array_replace([
            'comment'       => '',
            'attachmentIds' => [],
        ], $body);

        return $this->mutate(
            $user,
            $key,
            $revision,
            $transition->value,
            function (RepairRequest $request) use ($user, $transition, $body): array {
                $this->flag(
                    $request,
                    $user,
                    $transition === TransitionAction::Next ? 'canAdvance' : 'canReturn',
                );
                $isInitialSubmission = $transition === TransitionAction::Next
                    && $request->stage === RepairStage::Draft
                    && $user->role === UserRole::Customer;
                $isManagerDecision = $user->role === UserRole::Manager;
                $requiresComment = (!$isInitialSubmission && !$isManagerDecision) || ($isManagerDecision && $request->review_context === self::REVIEW_STATION_CHANGES && $transition === TransitionAction::Return);

                if ($requiresComment && trim((string) $body['comment']) === '') {
                    Input::fail('Укажите комментарий к решению');
                }

                if ($isManagerDecision && $body['attachmentIds'] !== []) {
                    Input::fail('Для решения УК вложения не используются');
                }

                $files = $this->attachmentIds(
                    $request,
                    $body['attachmentIds'],
                    AttachmentPurpose::Evidence,
                    true,
                );

                $from = $request->stage;
                $snapshot = $this->buildTransitionSnapshot($request);

                if ($transition === TransitionAction::Next) {
                    $this->validateNext($request);
                }

                $to = $this->transitionTarget($request, $from, $transition);
                if ($transition === TransitionAction::Next && $from === RepairStage::ManagerRouting && $to === RepairStage::StationApproval
                    && in_array($request->stationRecord?->uuid, $request->data['declinedStationIds'] ?? [], true)
                    && !($body['confirmDeclinedStation'] ?? false)) {
                    throw new ApiException(409, 'STATION_PREVIOUSLY_DECLINED', 'Это СТО ранее отказалось от выполнения данной заявки. Подтвердите повторное направление.');
                }
                $reviewContext = $request->review_context;
                $changes = $from === RepairStage::ManagerRouting
                    ? $this->estimateChanges($request)
                    : [];

                $this->prepareStationSchedule($request, $transition, $from, $to);
                $this->completeOrderIfNeeded($request, $transition, $from, (string) $body['comment']);

                if (
                    $from === RepairStage::ManagerRouting
                    && $to === RepairStage::StationApproval
                ) {
                    $managerChangedEstimate = $reviewContext === self::REVIEW_STATION_CHANGES;
                    $context = $managerChangedEstimate
                        ? self::REVIEW_MANAGER_CHANGES
                        : self::REVIEW_INITIAL;

                    $this->captureReviewBaseline($request, $context);
                }

                if (
                    $transition === TransitionAction::Next
                    && $from === RepairStage::ManagerRouting
                    && $to === RepairStage::CustomerApproval
                ) {
                    $request->review_context = self::REVIEW_INITIAL;
                }

                $this->moveToStage($request, $user, $to);

                $request->data = array_replace($request->data, ['routeComment' => '']);

                if (
                    $transition === TransitionAction::Return
                    && $request->stage->value <= RepairStage::VehicleIssue->value
                ) {
                    $request->repair_completed_at = null;
                }

                return [
                    'comment' => $transition->label() . ': '
                        . Workflow::STAGES[$from->value] . ' → '
                        . Workflow::STAGES[$request->stage->value]
                        . ($body['comment'] !== '' ? '. ' . $body['comment'] : ''),
                    'details' => [
                        'from'    => $from->value,
                        'to'      => $request->stage->value,
                        'changes' => $changes,
                    ],
                    'after'   => $this->transitionAfter(
                        $request,
                        $user,
                        $from,
                        $transition,
                        (string) $body['comment'],
                        $snapshot,
                        $files,
                        $changes,
                        $reviewContext,
                    ),
                ];
            },
        );
    }

    private function transitionTarget(
        RepairRequest $request,
        RepairStage $from,
        TransitionAction $action,
    ): RepairStage {
        if ($from === RepairStage::ManagerRouting) {
            if ($action === TransitionAction::Return) {
                return $request->review_context === self::REVIEW_STATION_CHANGES
                    ? RepairStage::StationApproval
                    : RepairStage::Draft;
            }

            if (
                $request->review_context === self::REVIEW_STATION_CHANGES
            ) {
                return RepairStage::CustomerApproval;
            }

            return RepairStage::StationApproval;
        }

        $target = $action === TransitionAction::Next
            ? $from->next()
            : $from->previous();

        if ($target === null) {
            throw new ApiException(
                422,
                'VALIDATION_ERROR',
                'Нельзя перейти за границу маршрута',
            );
        }

        return $target;
    }

    private function prepareStationSchedule(
        RepairRequest $request,
        TransitionAction $action,
        RepairStage $from,
        RepairStage $to,
    ): void {
        if (
            $action !== TransitionAction::Next
            || $from !== RepairStage::ManagerRouting
            || $to !== RepairStage::StationApproval
        ) {
            return;
        }

        $request->data = array_replace($request->data, [
            'stationSlot' => (string) (
                ($request->data['stationSlot'] ?? '')
                ?: ($request->data['slot'] ?? '')
            ),
        ]);
        $this->recalculatePlannedCompletion($request);
    }

    private function moveToStage(RepairRequest $request, User $user, RepairStage $to): void
    {
        $now = now();

        $request->periods()
            ->whereNull('exited_at')
            ->firstOrFail()
            ->update(['exited_at' => $now]);

        $request->stage = $to;
        if ($to === RepairStage::Closed && preg_match('/^\d{1,9}$/', (string) ($request->data['mileage'] ?? ''))) {
            $vehicle = Vehicle::query()->whereKey($request->vehicle_id)->lockForUpdate()->first();
            $mileage = (int) $request->data['mileage'];
            if ($vehicle !== null && $mileage > (int) ($vehicle->baseline_service_mileage ?? -1)) {
                $vehicle->forceFill([
                    'baseline_service_mileage' => $mileage,
                    'baseline_service_at' => $now,
                ])->save();
            }
        }

        RequestStatusPeriod::query()->create([
            'repair_request_id' => $request->id,
            'stage'             => $to->value,
            'entered_at'        => $now,
            'exited_at'         => $to === RepairStage::Closed ? $now : null,
            'actor_id'          => $user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransitionSnapshot(RepairRequest $request): array
    {
        return [
            'total'               => $this->repairRequestCalculator->total($request),
            'works'               => $request->workLines()->get()->toArray(),
            'parts'               => $request->parts()->get()->toArray(),
            'plannedCompletionAt' => $request->planned_completion_at?->toISOString(),
            'stationSlot'         => $request->data['stationSlot'] ?? '',
            'estimateVersion'     => $request->estimate_version,
        ];
    }

    private function completeOrderIfNeeded(
        RepairRequest $request,
        TransitionAction $action,
        RepairStage $from,
        string $comment,
    ): void {
        if ($action === TransitionAction::Next && $from === RepairStage::RepairInProgress) {
            $this->completeWorkOrder($request, $comment);
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<int, Attachment> $files
     * @param array<int, string> $changes
     * @param string $reviewContext
     *
     * @return callable(RequestEvent): void
     */
    private function transitionAfter(
        RepairRequest $request,
        User $user,
        RepairStage $from,
        TransitionAction $action,
        string $comment,
        array $snapshot,
        array $files,
        array $changes,
        string $reviewContext,
    ): callable {
        return function (RequestEvent $event) use (
            $request,
            $user,
            $from,
            $action,
            $comment,
            $snapshot,
            $files,
            $changes,
            $reviewContext,
        ): void {
            $this->createApproval(
                $request,
                $event,
                $user,
                $from,
                $action === TransitionAction::Next
                    ? ApprovalDecision::Approved
                    : ApprovalDecision::Returned,
                $comment,
                $snapshot,
            );

            $ids = array_map(static fn (Attachment $file) => $file->id, $files);

            Attachment::query()
                ->whereIn('id', $ids)
                ->update(['request_event_id' => $event->id]);

            if ($from === RepairStage::ManagerRouting) {
                if ($request->stage === RepairStage::CustomerApproval) {
                    $this->notifyStationEstimateApproved($request, $event);
                    $this->notifyCustomerFinalApproval($request, $event);
                } elseif (
                    $request->stage === RepairStage::StationApproval
                    && $reviewContext === self::REVIEW_STATION_CHANGES
                ) {
                    $this->notifyStationEstimateChanged($request, $event, [...$changes, 'Комментарий УК: ' . $comment]);
                }
            }
        };
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function createApproval(
        RepairRequest $request,
        RequestEvent $event,
        User $user,
        RepairStage $stage,
        ApprovalDecision $decision,
        string $comment,
        array $snapshot,
    ): void {
        RequestApproval::query()->create([
            'repair_request_id' => $request->id,
            'request_event_id'  => $event->id,
            'actor_id'          => $user->id,
            'stage'             => $stage->value,
            'decision'          => $decision,
            'comment'           => $comment,
            'estimate_version'  => $request->estimate_version,
            'snapshot'          => $snapshot,
        ]);
    }

    private function notifyWorkStarted(RepairRequest $request, RequestEvent $event): void
    {
        $recipients = User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where(function ($query) use ($request): void {
                $query
                    ->where(function ($customer) use ($request): void {
                        $customer
                            ->where('role', UserRole::Customer->value)
                            ->where('organization_id', $request->customer_organization_id);
                    })
                    ->orWhere(function ($manager) use ($request): void {
                        $manager
                            ->where('role', UserRole::Manager->value)
                            ->whereHas(
                                'organization',
                                fn ($organization) => $organization
                                    ->where('tenant_id', $request->tenant_id),
                            );
                    });
            })
            ->get();

        $slot = (string) ($request->data['stationSlot'] ?? $request->data['slot'] ?? '');
        $message = 'СТО подтвердила начало работ по заявке ' . $request->number;

        if ($slot !== '') {
            $message .= '. Подтверждённое время: ' . $slot;
        }

        foreach ($recipients as $recipient) {
            RequestNotification::query()->firstOrCreate(
                [
                    'user_id'          => $recipient->id,
                    'request_event_id' => $event->id,
                    'kind'             => 'work_started',
                ],
                [
                    'repair_request_id' => $request->id,
                    'title'             => 'Работы по ' . $request->number . ' начаты',
                    'message'           => $message,
                ],
            );
        }
    }

    private function notifySlotChanged(RepairRequest $request, RequestEvent $event): void
    {
        $recipients = User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Customer->value)
            ->where('organization_id', $request->customer_organization_id)
            ->get();
        $slot = (string) ($request->data['stationSlot'] ?? '');

        foreach ($recipients as $recipient) {
            RequestNotification::query()->firstOrCreate(
                [
                    'user_id'          => $recipient->id,
                    'request_event_id' => $event->id,
                    'kind'             => 'station_slot_changed',
                ],
                [
                    'repair_request_id' => $request->id,
                    'title'             => 'Время посещения СТО изменено',
                    'message'           => 'По заявке ' . $request->number
                        . ' СТО предложила новое время: ' . $slot,
                ],
            );
        }
    }

    /**
     * Сохраняет снимок сметы, относительно которого следующий участник
     * согласования будет видеть изменения.
     */
    private function captureReviewBaseline(RepairRequest $request, string $context): void
    {
        $request->review_context = $context;
        $request->review_baseline_version = $request->estimate_version;
        $request->review_baseline_snapshot = $this->estimateSnapshot($request);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function estimateSnapshot(RepairRequest $request): array
    {
        $parts = $request->parts()->orderBy('id')->get();
        $supplierNames = Supplier::query()
            ->whereIn('id', $parts->pluck('supplier_id')->filter()->all())
            ->pluck('name', 'id');

        return [
            'works' => $request->workLines()
                ->orderBy('id')
                ->get()
                ->map(static fn (RequestWork $work): array => [
                    'id'        => $work->uuid,
                    'name'      => $work->name,
                    'quantity'  => (string) $work->quantity,
                    'normHours' => $work->norm_hours !== null ? (string) $work->norm_hours : null,
                    'price'     => (string) $work->price,
                ])
                ->all(),
            'parts' => $parts
                ->map(static fn (RequestPart $part): array => [
                    'id'       => $part->uuid,
                    'name'     => $part->name,
                    'article'  => $part->article,
                    'unit'     => $part->unit,
                    'quantity' => (string) $part->quantity,
                    'price'    => (string) $part->price,
                    'supplier' => $supplierNames->get($part->supplier_id),
                ])
                ->all(),
        ];
    }

    /**
     * Формирует понятный перечень различий без изменения справочников.
     *
     * @return array<int, string>
     */
    private function estimateChanges(RepairRequest $request): array
    {
        $before = is_array($request->review_baseline_snapshot)
            ? $request->review_baseline_snapshot
            : ['works' => [], 'parts' => []];
        $after = $this->estimateSnapshot($request);

        return array_merge(
            $this->lineChanges('Работа', $before['works'] ?? [], $after['works']),
            $this->lineChanges('Запчасть', $before['parts'] ?? [], $after['parts']),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     *
     * @return array<int, string>
     */
    private function lineChanges(string $label, array $before, array $after): array
    {
        $changes = [];
        $maximum = max(count($before), count($after));
        $fieldLabels = [
            'name'      => 'наименование',
            'article'   => 'артикул',
            'unit'      => 'единица измерения',
            'quantity'  => 'количество',
            'normHours' => 'норма времени',
            'price'     => 'стоимость',
            'supplier'  => 'поставщик',
        ];

        for ($index = 0; $index < $maximum; $index++) {
            $old = $before[$index] ?? null;
            $new = $after[$index] ?? null;

            if ($old === null && $new !== null) {
                $changes[] = $label . ' добавлена: ' . ($new['name'] ?? 'без наименования');

                continue;
            }

            if ($old !== null && $new === null) {
                $changes[] = $label . ' удалена: ' . ($old['name'] ?? 'без наименования');

                continue;
            }

            foreach ($fieldLabels as $field => $fieldLabel) {
                $oldValue = $old[$field] ?? null;
                $newValue = $new[$field] ?? null;

                if ($oldValue === $newValue) {
                    continue;
                }

                $changes[] = sprintf(
                    '%s «%s»: %s %s → %s',
                    $label,
                    $new['name'] ?? $old['name'] ?? 'без наименования',
                    $fieldLabel,
                    $this->changeValue($oldValue),
                    $this->changeValue($newValue),
                );
            }
        }

        return $changes;
    }

    private function changeValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '«пусто»';
        }

        return '«' . (string) $value . '»';
    }

    private function approveCurrentEstimate(RepairRequest $request): void
    {
        foreach ($request->parts()->get() as $part) {
            $part->approved_version = $request->estimate_version;

            if ($part->status === RequestPartStatus::Proposed) {
                $part->status = RequestPartStatus::Approved;
            }

            $part->save();
        }
    }

    /**
     * @param array<int, string> $changes
     */
    private function notifyManagerReviewRequired(
        RepairRequest $request,
        RequestEvent $event,
        ApprovalDecision|string $reason,
        array $changes,
    ): void {
        $reasonLabel = match ($reason) {
            ApprovalDecision::Declined,
            self::REVIEW_STATION_DECLINED => 'СТО отказалась от заявки',
            self::REVIEW_CUSTOMER_SLOT => 'Заказчик предложил другое время',
            default => 'СТО изменила работы или запчасти',
        };
        $message = $reasonLabel . ' по заявке ' . $request->number . '.';

        if ($changes !== []) {
            $message .= ' Изменения: ' . implode('; ', $changes);
        }

        $this->notifyUsers(
            $this->managerUsers($request),
            $request,
            $event,
            'manager_review_required',
            'Требуется согласование УК',
            $message,
        );
    }

    private function notifyCustomerFinalApproval(
        RepairRequest $request,
        RequestEvent $event,
    ): void {
        $this->notifyUsers(
            $this->customerUsers($request),
            $request,
            $event,
            'customer_final_approval',
            'СТО готова принять автомобиль',
            'Работы, запчасти и время визита по заявке ' . $request->number
                . ' согласованы. Подтвердите финальную заявку.',
        );
    }

    private function notifyVehicleExpected(RepairRequest $request, RequestEvent $event): void
    {
        $this->notifyUsers(
            $this->stationUsers($request),
            $request,
            $event,
            'vehicle_expected',
            'Ожидается автомобиль',
            'Заказчик подтвердил заявку ' . $request->number
                . '. После прибытия оформите акт приёма ТС.',
        );
    }

    private function notifyStationEstimateApproved(
        RepairRequest $request,
        RequestEvent $event,
    ): void {
        $this->notifyUsers(
            $this->stationUsers($request),
            $request,
            $event,
            'station_estimate_approved',
            'Изменения согласованы УК',
            'УК согласовала работы и запчасти по заявке ' . $request->number . '.',
        );
    }

    /**
     * @param array<int, string> $changes
     */
    private function notifyStationEstimateChanged(
        RepairRequest $request,
        RequestEvent $event,
        array $changes,
    ): void {
        $this->notifyUsers(
            $this->stationUsers($request),
            $request,
            $event,
            'station_estimate_changed',
            'УК изменила работы или запчасти',
            'По заявке ' . $request->number . ' изменено: ' . implode('; ', $changes),
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function managerUsers(RepairRequest $request): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Manager->value)
            ->whereHas(
                'organization',
                fn ($query) => $query->where('tenant_id', $request->tenant_id),
            )
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function customerUsers(RepairRequest $request): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Customer->value)
            ->where('organization_id', $request->customer_organization_id)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function stationUsers(RepairRequest $request): Collection
    {
        $organizationId = $request->stationRecord?->organization_id;

        return User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Station->value)
            ->where('organization_id', $organizationId ?? 0)
            ->get();
    }

    /**
     * @param iterable<int, User> $recipients
     */
    private function notifyUsers(
        iterable $recipients,
        RepairRequest $request,
        RequestEvent $event,
        string $kind,
        string $title,
        string $message,
    ): void {
        foreach ($recipients as $recipient) {
            RequestNotification::query()->firstOrCreate(
                [
                    'user_id'          => $recipient->id,
                    'request_event_id' => $event->id,
                    'kind'             => $kind,
                ],
                [
                    'repair_request_id' => $request->id,
                    'title'             => $title,
                    'message'           => $message,
                ],
            );
        }
    }

    /* ─────────────────── Валидация переходов ─────────────────── */

    private function validateNext(RepairRequest $request): void
    {
        $stage = $request->stage;

        if ($stage === RepairStage::Draft) {
            $this->validateDraftStage($request, false);
        }

        if ($stage === RepairStage::ManagerRouting) {
            $this->validateDraftStage($request, true);
        }

        if ($stage === RepairStage::ManagerRouting && !$request->assigned_station_id) {
            Input::fail('Назначьте СТО');
        }

        if ($stage === RepairStage::ManagerRouting) {
            $this->validateManagerReviewStage($request);
        }

        if ($stage === RepairStage::RepairInProgress) {
            if (!$request->acceptanceAct()->exists()) {
                Input::fail('Сначала оформите акт приёма ТС');
            }

            $this->validateEstimateStage($request);
            $this->validatePartsReceivedStage($request);
        }

        if (
            in_array($stage, [
                RepairStage::Ready,
                RepairStage::VehicleIssue,
                RepairStage::ClosingDocuments,
            ], true)
            && !$request->workOrder()->exists()
        ) {
            Input::fail('Заказ-наряд ещё не сформирован');
        }

        if ($stage === RepairStage::ClosingDocuments) {
            $this->validateInvoiceIssuedStage($request);
        }

        if ($stage === RepairStage::AwaitingPayment) {
            $this->validateInvoiceSubmittedStage($request);
        }

        if ($stage === RepairStage::PaymentReview) {
            $this->validateInvoicePaidStage($request);
        }
    }

    private function validateDraftStage(
        RepairRequest $request,
        bool $requireVisitTime,
    ): void {
        if (!$request->vehicle_id) {
            Input::fail('Выберите автомобиль из перечня вашей организации');
        }

        if (!$request->reason_id) {
            Input::fail('Выберите причину обращения');
        }

        if (($request->data['mileage'] ?? '') === '') {
            Input::fail('Укажите текущий пробег');
        }

        $hasActiveContact = $request->contact_id !== null
            && VehicleContact::query()
                ->whereKey($request->contact_id)
                ->where('vehicle_id', $request->vehicle_id)
                ->where('kind', PersonKind::Contact->value)
                ->where('active', true)
                ->exists();

        $contactSnapshot = $request->contact_snapshot;
        $hasStoredContact = is_array($contactSnapshot)
            && trim((string) ($contactSnapshot['fullName'] ?? '')) !== ''
            && trim((string) ($contactSnapshot['phone'] ?? '')) !== '';

        if (!$hasActiveContact && !$hasStoredContact) {
            Input::fail('Выберите контактное лицо');
        }

        if ($requireVisitTime && ($request->data['slot'] ?? '') === '') {
            Input::fail('Укажите желаемое время визита');
        }
    }

    private function validateEstimateStage(RepairRequest $request): void
    {
        if (!$request->diagnoses()->exists()) {
            Input::fail('СТО должна сохранить заключение диагностики');
        }

        if (!$request->planned_completion_at) {
            Input::fail('Укажите плановую дату и время завершения');
        }

        if (!$request->workLines()->exists()) {
            Input::fail('Заполните перечень работ');
        }

        foreach ($request->workLines()->get() as $work) {
            if (trim((string) $work->name) === '' || $work->quantity <= 0) {
                Input::fail('Заполните названия и положительное количество работ');
            }

            $this->validateWorkPricing($work);
        }

        foreach ($request->parts()->get() as $part) {
            if (!$part->supplier_id || trim((string) $part->name) === '' || $part->quantity <= 0) {
                Input::fail('Укажите поставщика, название и количество каждой запчасти');
            }
        }
    }

    private function validateManagerReviewStage(RepairRequest $request): void
    {
        if (!$request->workLines()->exists()) {
            Input::fail('Добавьте хотя бы одну работу');
        }

        foreach ($request->workLines()->get() as $work) {
            if (trim((string) $work->name) === '' || $work->quantity <= 0) {
                Input::fail('Заполните название и положительное количество работы');
            }

            $this->validateWorkPricing($work);
        }
    }

    private function validateWorkPricing(RequestWork $work): void
    {
        if (
            $work->work_type_id === null
            || $work->norm_hours === null
            || $work->hourly_rate === null
            || $work->line_amount === null
        ) {
            Input::fail(
                'Для каждой работы выберите норму времени '
                . 'и укажите стоимость нормо-часа',
            );
        }

        if ($work->norm_is_manual && trim((string) $work->norm_override_reason) === '') {
            Input::fail('Для ручной нормы требуется причина корректировки');
        }
    }

    private function validatePartsReceivedStage(RepairRequest $request): void
    {
        $approved = $request->approvals()
            ->where('stage', RepairStage::CustomerApproval->value)
            ->where('decision', ApprovalDecision::Accepted->value)
            ->where('estimate_version', $request->estimate_version)
            ->exists();

        if (!$approved) {
            Input::fail('Текущая смета должна быть согласована Заказчиком');
        }

        foreach ($request->parts()->get() as $part) {
            $needsReceive = $part->quantity > $part->returned_quantity;
            $notReady = $part->approved_version !== $request->estimate_version
                || !$part->status->isReadyForRepair();

            if ($needsReceive && $notReady) {
                Input::fail('Подтвердите получение согласованных запчастей');
            }
        }
    }

    private function validateInvoiceIssuedStage(RepairRequest $request): void
    {
        $invoice = $request->invoice;

        $matchesTotal = $invoice !== null
            && bccomp((string) $invoice->amount, (string) $request->workOrder->total, 2) === 0;

        if (!$invoice || $invoice->status !== InvoiceStatus::Issued || !$matchesTotal) {
            Input::fail('Прикрепите счёт на полную сумму заказ-наряда');
        }
    }

    private function validateInvoiceSubmittedStage(RepairRequest $request): void
    {
        if ($request->invoice?->status !== InvoiceStatus::Submitted) {
            Input::fail('Прикрепите закрытый счёт и сведения об оплате');
        }
    }

    private function validateInvoicePaidStage(RepairRequest $request): void
    {
        $invoice = $request->invoice;

        $isPaid = $invoice !== null
            && $invoice->status === InvoiceStatus::Paid
            && $invoice->paid_document_attachment_id !== null
            && bccomp((string) $invoice->paid_amount, (string) $invoice->amount, 2) >= 0;

        if (!$isPaid) {
            Input::fail('УК должна проверить оплату и закрытый счёт');
        }
    }

    /* ─────────────────── Заказ-наряд ─────────────────── */

    private function completeWorkOrder(RepairRequest $request, string $conclusion): void
    {
        $request->repair_completed_at = now();
        $order = $request->workOrder;

        if ($order !== null) {
            $this->snapshotWorkOrder($order);
            WorkOrderItem::query()->where('work_order_id', $order->id)->delete();
        } else {
            $order = new WorkOrder(['repair_request_id' => $request->id]);
        }

        $order->fill([
            'vehicle_id'       => $request->vehicle_id,
            'station_id'       => $request->assigned_station_id,
            'mileage'          => $request->acceptanceAct?->mileage
                ?? (int) $request->data['mileage'],
            'completed_at'     => $request->repair_completed_at,
            'total'            => $this->repairRequestCalculator->total($request),
            'conclusion'       => $conclusion,
            'estimate_version' => $request->estimate_version,
        ]);

        $order->save();

        $this->createWorkOrderItemsFromWorks($request, $order);
        $this->createWorkOrderItemsFromParts($request, $order);
    }

    private function snapshotWorkOrder(WorkOrder $order): void
    {
        DB::table('work_order_versions')->insert([
            'uuid'         => (string) Str::uuid(),
            'work_order_id' => $order->id,
            'snapshot'     => json_encode(
                [
                    'order' => $order->toArray(),
                    'items' => WorkOrderItem::query()->where('work_order_id', $order->id)->get()->toArray(),
                ],
                JSON_THROW_ON_ERROR,
            ),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function createWorkOrderItemsFromWorks(RepairRequest $request, WorkOrder $order): void
    {
        foreach ($request->workLines()->get() as $work) {
            $item = WorkOrderItem::query()->create([
                'work_order_id'  => $order->id,
                'kind'           => WorkOrderItemKind::Work,
                'name'           => $work->name,
                'quantity'       => $work->quantity,
                'price'          => $work->price,
                'work_type_id'   => $work->work_type_id,
                'norm_hours'     => $work->norm_hours,
                'actual_hours'   => $work->actual_hours,
                'is_maintenance' => $work->is_maintenance || (bool) $request->reasonRecord?->is_maintenance,
            ]);

            VehicleServiceRecord::query()->create([
                'vehicle_id'          => $request->vehicle_id,
                'repair_request_id'   => $request->id,
                'work_order_item_id'  => $item->id,
                'mileage'             => $order->mileage,
                'performed_at'        => $order->completed_at,
                'is_maintenance'      => $item->is_maintenance,
            ]);
        }
    }

    private function createWorkOrderItemsFromParts(RepairRequest $request, WorkOrder $order): void
    {
        foreach ($request->parts()->get() as $part) {
            if ($part->quantity <= $part->returned_quantity) {
                continue;
            }

            WorkOrderItem::query()->create([
                'work_order_id' => $order->id,
                'kind'          => WorkOrderItemKind::Part,
                'name'          => $part->name,
                'quantity'      => bcsub((string) $part->quantity, (string) $part->returned_quantity, self::QUANTITY_SCALE),
                'price'         => $part->price,
                'supplier_id'   => $part->supplier_id,
            ]);

            $part->update(['status' => RequestPartStatus::Installed]);
        }
    }
}
