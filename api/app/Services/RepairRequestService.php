<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\AcceptVehicleDto;
use App\DTO\ApprovalDto;
use App\DTO\CustomerSlotDecisionDto;
use App\DTO\NotificationDto;
use App\DTO\RequestEventDto;
use App\DTO\StationDecisionDto;
use App\DTO\TransitionContext;
use App\DTO\TransitionDto;
use App\DTO\UpdateRepairRequestDto;
use App\DTO\UserDto;
use App\Enums\ApprovalDecision;
use App\Enums\AttachmentPurpose;
use App\Enums\PersonKind;
use App\Enums\RepairStage;
use App\Enums\RequestDocumentType;
use App\Enums\ReviewContext;
use App\Enums\RequestPartStatus;
use App\Enums\TransitionAction;
use App\Enums\UserRole;
use App\Enums\WorkOrderItemKind;
use App\Events\RepairRequests\RepairRequestWaitingVehicle;
use App\Events\RepairRequests\ClosingDocumentsRequested;
use App\Events\RepairRequests\VehicleAccepted;
use App\Events\RepairRequests\WorkOrderCompleted;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\Recommendation;
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
use App\Models\Warehouse;
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
    public const NO_WORKS_MESSAGE = 'В ЗР нет работ. Добавьте хотя бы одну работу, чтобы перевести ЗР далее по маршруту';

    private const DB_DEADLOCK_RETRIES = 3;
    private const QUANTITY_SCALE = 3;

    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly Workflow $workflow,
        private readonly RepairRequestCalculator $repairRequestCalculator,
        private readonly TimeNormSelectionService $timeNormSelection,
        private readonly WorkCostService $workCost,
        private readonly ApiPresenter $apiPresenter,
        private readonly DocumentLockService $documentLocks,
        private readonly ClosingDocumentService $closingDocuments,
    ) {}

    /* ─────────────────── Создание ─────────────────── */

    public function create(User $user): RepairRequest
    {
        $userDto = UserDto::fromUserModel($user);
        $this->validateRole($userDto->role);

        return DB::transaction(function () use ($userDto): RepairRequest {
            $uuid = (string)Str::uuid();

            $req = RepairRequest::query()->create([
                'uuid'                     => $uuid,
                'public_id'                => $uuid,
                'tenant_id'                => $userDto->tenantId,
                'customer_organization_id' => $userDto->organizationId,
                'created_by'               => $userDto->userId,
                'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
                'works'                    => [],
                'history'                  => [],
                'stage'                    => RepairStage::Draft,
                'revision'                 => 0,
                'review_context'           => ReviewContext::Initial,
                'review_baseline_version'  => 0,
            ]);

            RequestStatusPeriod::query()->create([
                'repair_request_id' => $req->id,
                'stage'             => RepairStage::Draft->value,
                'entered_at'        => now(),
                'actor_id'          => $userDto->userId,
            ]);

            $dto = RequestEventDto::fromRequest(
                request: $req,
                userDto: $userDto,
                action: 'create',
                comment: 'Создан пустой черновик'
            );

            $this->event($dto);

            return $req->refresh();
        });
    }

    private function validateRole(UserRole $role): void
    {
        if ($role !== UserRole::Customer) {
            throw new ApiException(403, 'FORBIDDEN', 'Заявку создаёт Заказчик');
        }
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
        $userDto = UserDto::fromUserModel($user);

        $validated = Input::validate(['ids' => $ids], [
            'ids'   => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        /** @var array<int, array{disk: string, key: string}> $files */
        $files = [];

        $deleted = DB::transaction(function () use ($userDto, $validated, &$files): array {
            $documents = RepairRequest::query()
                ->with(['attachments', 'chat'])
                ->where('tenant_id', $userDto->tenantId)
                ->where('tenant_id', $userDto->tenantId)
                ->where('created_by', $userDto->userId)
                ->where('stage', RepairStage::Draft->value)
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

                $this->documentLocks->assertAvailable($userDto, 'request', $document->uuid);

                foreach ($document->attachments as $attachment) {
                    $files[] = [
                        'disk' => $attachment->disk,
                        'key'  => $attachment->object_key,
                    ];
                }

                $this->documentLocks->releaseResource($userDto, 'request', $document->uuid);
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

    public function flag(RepairRequest $req, UserDto $userDto, string $flag): void
    {
        $permissions = Workflow::permissions($req, $userDto);

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
        $userDto = UserDto::fromUserModel($user);

        return DB::transaction(function () use ($userDto, $key, $revision, $action, $change): RepairRequest {
            $req = RepairRequest::resolveFor($userDto, $key, true);
            $this->documentLocks->assertAvailable($userDto, 'request', $req->uuid);

            if ($req->revision !== $revision) {
                throw new ApiException(409, 'REVISION_CONFLICT', 'Заявка уже изменена другим пользователем');
            }

            $result = $change($req) ?? [];

            $req->revision++;
            $req->save();

            $dto = RequestEventDto::fromRequest(
                $req,
                $userDto,
                $action,
                $result['comment'] ?? $action,
                $result['details'] ?? [],
            );

            $event = $this->event($dto);

            if (isset($result['after'])) {
                ($result['after'])($event);
            }

            return $req->refresh();
        }, self::DB_DEADLOCK_RETRIES);
    }

    public function event(RequestEventDto $dto): RequestEvent
    {
        return RequestEvent::query()->create([
            'repair_request_id' => $dto->requestId,
            'actor_id'          => $dto->userId,
            'actor_label'       => $dto->actorLabel,
            'action'            => $dto->action,
            'comment'           => $dto->comment,
            'details'           => $dto->details,
            'stage'             => $dto->stage,
            'revision'          => $dto->revision,
            'occurred_at'       => now(),
        ]);
    }

    public function createFromRecommendation(UserDto $userDto, Recommendation $doc): RepairRequest
    {
        $vehicle = Vehicle::query()
            ->where('tenant_id', $userDto->tenantId)
            ->findOrFail($doc->vehicle_id);
        $uuid = (string) Str::uuid();
        $data = array_fill_keys(Workflow::TEXT_FIELDS, '');
        $data['recommendationVehicleLocked'] = true;
        $source = RepairRequest::query()->find($doc->source_request_id);
        if ($userDto->role !== UserRole::Customer && $source !== null) {
            $data['mileage'] = (string) ($source->data['mileage'] ?? '');
            $data['laborRateType'] = (string) ($source->data['laborRateType'] ?? 'general');
        }
        $previous = $this->vehicleService->previousMileageDetails($vehicle, $userDto);
        $data['laborRateType'] = (string) ($source?->data['laborRateType'] ?? 'general');
        if ($userDto->role !== UserRole::Customer && $previous['mileage'] !== null) {
            $data['mileage'] = (string) max((int) ($data['mileage'] ?: 0), $previous['mileage']);
        }
        $assignedStationId = $userDto->role === UserRole::Station
            ? Station::query()->where('organization_id', $userDto->organizationId)->value('id')
            : null;

        $req = RepairRequest::query()->create([
            'uuid'                      => $uuid,
            'public_id'                 => $uuid,
            'tenant_id'                 => $userDto->tenantId,
            'customer_organization_id'  => $doc->customer_organization_id,
            'vehicle_id'                => $vehicle->id,
            'created_by'                => $userDto->userId,
            'assigned_station_id'       => $assignedStationId,
            'data'                      => $data,
            'works'                     => [],
            'history'                   => [],
            'previous_service_mileage'  => $previous['mileage'],
            'previous_service_at'       => $previous['recordedAt'],
            'stage'                     => RepairStage::Draft,
            'revision'                  => 0,
            'review_context'            => ReviewContext::Initial,
            'review_baseline_version'   => 0,
        ]);

        RequestStatusPeriod::query()->create([
            'repair_request_id' => $req->id,
            'stage'             => 0,
            'entered_at'        => now(),
            'actor_id'          => $userDto->userId,
        ]);

        $dto = RequestEventDto::fromRequest(
            request: $req,
            userDto: $userDto,
            action: 'create',
            comment: 'Черновик создан из рекомендаций ' . $doc->number
        );
        $this->event($dto);

        return $req;
    }

    /**
     * Смета изменена: новая версия требует согласования. На стадии «В работе»
     * версия не меняется до согласования изменений (см. WorkChangeService),
     * чтобы согласованные запчасти можно было продолжать заказывать и получать.
     */
    public function markEstimateChanged(RepairRequest $req): void
    {
        if ($req->stage === RepairStage::RepairInProgress) {
            return;
        }

        $req->estimate_version++;

        RequestPart::query()
            ->where('repair_request_id', $req->id)
            ->update(['approved_version' => null]);
    }

    public function recommendationEstimateChanged(RepairRequest $req, UserDto $userDto, bool $restored): void
    {
        if ($restored && $req->stage === RepairStage::Draft) {
            $req->data = array_replace($req->data, ['recommendationVehicleLocked' => true]);
        }
        $this->markEstimateChanged($req);

        $this->recalculatePlannedCompletion($req);
        if ($restored && $userDto->role === UserRole::Customer && $req->stage === RepairStage::CustomerApproval) {
            $this->moveToStage($req, $userDto->userId, RepairStage::ManagerRouting);
            $this->captureReviewBaseline($req, ReviewContext::Initial);
        }
    }

    public function notifyRecommendationReview(RepairRequest $req, UserRole $role, int $eventId): void
    {
        if ($role === UserRole::Customer && $req->stage === RepairStage::ManagerRouting) {
            $dto = NotificationDto::fromRequest(
                $this->managerUsers($req->tenant_id),
                $req->id,
                $eventId,
                'manager_review_required',
                'Добавлены рекомендации',
                'Заказчик добавил работы или запчасти из рекомендаций. Требуется согласование сметы.'
            );
            $this->notifyUsers($dto);
        }
    }

    /* ─────────────────── Обновление полей ─────────────────── */

    /**
     * @param array<string, mixed> $patch
     *
     * @throws Throwable
     */
    public function update(User $user, UpdateRepairRequestDto $dto): RepairRequest
    {
        $userDto = UserDto::fromUserModel($user);
        if ($dto->isEmpty()) {
            Input::fail('Передайте хотя бы одно изменяемое поле');
        }

        return $this->mutate(
            $user,
            $dto->requestKey,
            $dto->revision,
            'patch',
            function (RepairRequest $req) use ($userDto, $dto): array {
                // 1. Проверка прав на изменение полей
                $this->assertPatchFieldsAllowed($req, $userDto, $dto->rawPatch);

                // 2. Валидация времени и пробега (используем rawPatch, т.к. там могут быть строки)
                $this->validateLocalTimeFields($dto->rawPatch);
                $this->validateMileage($dto->rawPatch);

                // 3. Применение изменений (используем DTO для явных полей)
                $this->applyVehicleChange($req, $userDto, $dto->vehicleId);
                $this->applyContactChange($req, $dto->contactId);
                $this->applyDriverChange($req, $dto->driverId);
                $this->applyStationChange($req, $dto->stationId);
                $this->applyPartsFromManagerChange($req, $dto->rawPatch);
                $this->applyReasonChange($req, $userDto, $dto->reasonId);
                $this->applyReasonSubtypeChange($req, $dto->reasonSubtypeId);

                // 4. Обновление текстовых полей (из rawPatch)
                $req->data = array_replace(
                    $req->data,
                    array_intersect_key($dto->rawPatch, array_flip(Workflow::TEXT_FIELDS)),
                );

                if (array_key_exists('slot', $dto->rawPatch)) {
                    $req->data = array_replace($req->data, ['stationSlot' => '']);
                }

                $this->assertMileageNotLessThanPrevious($req);

                // 5. Работа с запчастями и работами
                if ($dto->works !== null || $dto->parts !== null) {
                    // На стадии «В работе» фиксируем согласованную смету до правки.
                    WorkChangeService::begin($req);
                }

                if ($dto->parts !== null) {
                    app(RequestPartService::class)->replace($req, $dto->parts);
                }

                if ($dto->works !== null || $dto->laborRateType !== null) {
                    $works = $dto->works ?? app(ApiPresenter::class)->document($req, $userDto)['works'];
                    $this->replaceWorks($req, $works, $userDto->role === UserRole::Customer);
                }

                // Смена СТО: строки получают агентскую скидку новой СТО;
                // «ЗЧ от УК» — агентская скидка не применяется (0 %).
                if ($req->isDirty('assigned_station_id') || $req->isDirty('parts_from_manager')) {
                    $agentDiscount = self::stationAgentDiscount($req);
                    foreach ([RequestWork::class, RequestPart::class] as $lineModel) {
                        $lineModel::query()
                            ->where('repair_request_id', $req->id)
                            ->update(['agent_discount_percent' => $agentDiscount]);
                    }
                }

                if ($dto->works !== null || $dto->laborRateType !== null || $dto->parts !== null) {
                    $this->markEstimateChanged($req);
                    app(WorkChangeService::class)->clearIfUnchanged($req);
                }

                if ($this->touchFields($dto)) {
                    $this->recalculatePlannedCompletion($req);
                }

                return ['comment' => 'Сохранены поля: ' . implode(', ', array_keys($dto->rawPatch))];
            }
        );
    }

    private function touchFields(UpdateRepairRequestDto $dto)
    {
        if ($dto->works !== null) {
            return true;
        }

        if (array_key_exists('slot', $dto->rawPatch)) {
            return true;
        }

        if (array_key_exists('stationSlot', $dto->rawPatch)) {
            return true;
        }

        if ($dto->stationId !== null) {
            return true;
        }

        return false;
    }

    private function assertPatchFieldsAllowed(RepairRequest $req, UserDto $userDto, array $patch): void
    {
        if (array_diff(array_keys($patch), Workflow::FIELDS)) {
            Input::fail('Обнаружены поля, которые нельзя изменять');
        }

        $allowed = Workflow::permissions($req, $userDto)['editableFields'];

        if (!$allowed || array_diff(array_keys($patch), $allowed)) {
            throw new ApiException(403, 'FORBIDDEN', 'Поля на текущем этапе недоступны');
        }
    }

    private function validateLocalTimeFields(array $patch): void
    {
        $minimum = now()->addMinutes(30)->startOfMinute();
        $minuteRemainder = (int) $minimum->format('i') % 30;

        if ($minuteRemainder !== 0) {
            $minimum = $minimum->addMinutes(30 - $minuteRemainder);
        }

        foreach (['slot', 'stationSlot'] as $field) {
            // Guard Clause 1: если поля нет в патче, сразу идем к следующему
            if (!isset($patch[$field])) {
                continue;
            }

            $date = Input::localTime($patch[$field]);

            // Guard Clause 2: если дата не распарсилась, делать нечего
            if ($date === null) {
                continue;
            }

            // Guard Clause 3: проверка минимального времени
            if ($date->lt($minimum)) {
                Input::fail('Дата и время должны быть не ранее текущего времени плюс 30 минут', $field);
            }

            // Проверяем время визита
            $tz = (string) config('sto.service_timezone');
            $localTime = $date->setTimezone($tz)->format('H:i');
            $visitTimeStart = (string) config('sto.visit_time_start');
            $visitTimeEnd = (string) config('sto.visit_time_end');

            if ($localTime < $visitTimeStart || $localTime > $visitTimeEnd) {
                Input::fail("Время визита должно быть с $visitTimeStart до $visitTimeEnd", $field);
            }
        }
    }

    private function validateMileage(array $patch): void
    {
        $mileage = isset($patch['mileage']) && $patch['mileage'] !== '' ? (string)$patch['mileage'] : false;
        if ($mileage && !preg_match('/^\d{1,9}$/', $mileage)) {
            Input::fail('Пробег должен быть целым числом', 'mileage');
        }
    }

    private function applyVehicleChange(RepairRequest $req, UserDto $userDto, ?string $vehicleId): void
    {
        if ($vehicleId === null) {
            return;
        }

        $vehicle = $vehicleId === '' ? null : Vehicle::query()
            ->where('tenant_id', $req->tenant_id)
            ->where('organization_id', $req->customer_organization_id)
            ->where('uuid', $vehicleId)
            ->first();

        if ($vehicleId !== '' && !$vehicle) {
            Input::fail('Автомобиль не входит в перечень организации', 'vehicleId');
        }

        if ($req->vehicle_id !== $vehicle?->id) {
            $req->vehicle_id = $vehicle?->id;

            $previous = $vehicle !== null
                ? $this->vehicleService->previousMileageDetails($vehicle, $userDto, $req->id)
                : ['mileage' => null, 'recordedAt' => null];

            $req->previous_service_mileage = $previous['mileage'];
            $req->previous_service_at = $previous['recordedAt'];
            $req->contact_id = null;
            $req->contact_snapshot = null;
            $req->driver_id = null;
            $req->driver_snapshot = null;
            $req->data = array_replace($req->data, ['contact' => '']);
        }
    }

    /** Галочка «ЗЧ от УК» (УК, этап «Маршрутизация УК»). */
    private function applyPartsFromManagerChange(RepairRequest $req, array $patch): void
    {
        if (!array_key_exists('partsFromManager', $patch)) {
            return;
        }
        $value = (bool) $patch['partsFromManager'];
        if ($value !== (bool) $req->parts_from_manager && $req->parts_transfer_document_id !== null) {
            Input::fail('ЗЧ уже перемещены со склада УК на склад СТО — изменить «ЗЧ от УК» нельзя', 'partsFromManager');
        }
        $req->parts_from_manager = $value;
    }

    private function applyContactChange(RepairRequest $req, ?string $contactId): void
    {
        if ($contactId === null) {
            return;
        }

        if ($contactId === '') {
            $req->contact_id = null;
            $req->contact_snapshot = null;
            $req->data = array_replace($req->data, ['contact' => '']);

            return;
        }

        if ($req->vehicle_id === null) {
            Input::fail('Сначала выберите автомобиль', 'vehicleId');
        }

        $contact = VehicleContact::query()
            ->where('vehicle_id', $req->vehicle_id)
            ->where('kind', PersonKind::Contact->value)
            ->where('uuid', $contactId)
            ->where('active', true)
            ->first();

        if ($contact === null) {
            Input::fail('Контактное лицо не относится к выбранному автомобилю', 'contactId');
        }

        $req->contact_id = $contact->id;
        $req->contact_snapshot = $contact->snapshot();
        $req->data = array_replace(
            $req->data,
            ['contact' => $contact->fullName() . ' · ' . $contact->phone],
        );
    }

    private function applyDriverChange(RepairRequest $req, ?string $driverId): void
    {
        if ($driverId === null) {
            return;
        }

        if ($driverId === '') {
            $req->driver_id = null;
            $req->driver_snapshot = null;

            return;
        }

        if ($req->vehicle_id === null) {
            Input::fail('Сначала выберите автомобиль', 'vehicleId');
        }

        $driver = VehicleContact::query()
            ->where('vehicle_id', $req->vehicle_id)
            ->where('kind', PersonKind::Driver->value)
            ->where('uuid', $driverId)
            ->where('active', true)
            ->first();

        if ($driver === null) {
            Input::fail('Водитель не относится к выбранному автомобилю', 'driverId');
        }

        $req->driver_id = $driver->id;
        $req->driver_snapshot = $driver->snapshot();
    }

    private function applyStationChange(RepairRequest $req, ?string $stationId): void
    {
        if ($stationId === null) {
            return;
        }

        $station = $stationId === ''
            ? null
            : Station::query()
                ->where('tenant_id', $req->tenant_id)
                ->where('active', true)
                ->where('uuid', $stationId)
                ->first();

        if ($stationId !== '' && !$station) {
            Input::fail('Выберите доступную СТО');
        }

        $req->assigned_station_id = $station?->id;
    }

    private function applyReasonChange(RepairRequest $req, UserDto $userDto, ?string $reasonId): void
    {
        if ($reasonId === null) {
            return;
        }

        $reason = $reasonId === ''
            ? null
            : ServiceReason::query()
                ->where('tenant_id', $req->tenant_id)
                ->whereNull('parent_id')
                ->where('active', true)
                ->when(
            $userDto->role === UserRole::Customer,
                    fn ($query) => $query->whereIn('code', ['maintenance', 'diagnostics']),
                )
                ->where('uuid', $reasonId)
                ->first();

        if ($reasonId !== '' && !$reason) {
            Input::fail('Выберите причину из справочника');
        }

        if ($req->reason_id !== $reason?->id) {
            $req->reason_subtype_id = null;
        }

        $req->reason_id = $reason?->id;
    }

    private function applyReasonSubtypeChange(RepairRequest $req, ?string $reasonSubtypeId): void
    {
        if ($reasonSubtypeId === null) {
            return;
        }

        $subtype = $reasonSubtypeId === ''
            ? null
            : ServiceReason::query()
                ->where('tenant_id', $req->tenant_id)
                ->where('parent_id', $req->reason_id)
                ->where('active', true)
                ->where('uuid', $reasonSubtypeId)
                ->first();

        if ($reasonSubtypeId !== '' && (!$req->reason_id || !$subtype)) {
            Input::fail('Подвид не относится к выбранной причине');
        }

        $req->reason_subtype_id = $subtype?->id;
    }

    private function assertMileageNotLessThanPrevious(RepairRequest $req): void
    {
        $mileage = $req->data['mileage'] ?? '';
        $prev_mil = $req->previous_service_mileage;

        if ($mileage !== '' && $prev_mil !== null && (int)$mileage < $prev_mil) {
            Input::fail('Пробег меньше пробега на прошлом ТО');
        }
    }

    /* ─────────────────── Работы ─────────────────── */

/**
     * Агентская скидка назначенной СТО, % (null — СТО ещё не выбрана).
     */
    public static function stationAgentDiscount(RepairRequest $req): ?string
    {
        // «ЗЧ от УК»: УК зарабатывает на наценке ЗЧ, агентской скидки нет
        // ни по работам, ни по ЗЧ.
        if ($req->parts_from_manager) {
            return '0';
        }

        if ($req->assigned_station_id === null) {
            return null;
        }

        $percent = Station::query()
            ->whereKey($req->assigned_station_id)
            ->value('agent_discount_percent');

        return $percent !== null
            ? (string) $percent
            : (string) Station::DEFAULT_AGENT_DISCOUNT_PERCENT;
    }

    /**
     * @param bool $customerEntry Работы вносит Заказчик (черновик): нормы
     *                            времени он не заполняет — норма подбирается
     *                            из справочника, если она однозначна, а
     *                            ранее заданная УК норма строки сохраняется.
     */
    private function replaceWorks(RepairRequest $req, array $works, bool $customerEntry = false): void
    {
        $works = array_values(array_filter(
            $works,
            static fn (mixed $work): bool => !is_array($work)
                || !empty($work['workTypeId'])
                || trim((string) ($work['name'] ?? '')) !== '',
        ));
        $rows = [];
        $vehicle = $req->vehicleRecord()->first();
        $hourlyRate = $vehicle?->laborRate((string) ($req->data['laborRateType'] ?? 'general'));
        // Агентская скидка не редактируется в заявке: берётся из реквизитов СТО.
        $agentDiscount = self::stationAgentDiscount($req);
        // Сохранённые строки сохраняют идентификатор: по нему отслеживаются
        // изменения на стадии «В работе».
        $existingWorks = RequestWork::query()
            ->where('repair_request_id', $req->id)
            ->get()
            ->keyBy('uuid');
        $existingIds = $existingWorks->keys()->all();
        $usedIds = [];

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
                'price'       => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
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
                // Только для чтения: скидка берётся из реквизитов СТО.
                'agentDiscountPercent' => ['sometimes', 'nullable'],
                'agentDiscountAmount' => ['sometimes', 'nullable'],
                'stationAmount' => ['sometimes', 'nullable'],
                'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'changeNote' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]);

            $type = $this->resolveWorkType($req, $work);
            $selection = $type !== null && $vehicle !== null
                ? $this->timeNormSelection->select($type, $vehicle)
                : [
                    'status' => TimeNormSelectionService::STATUS_MISSING,
                    'norm' => null,
                    'scope' => null,
                    'conflicts' => [],
                ];

            if ($customerEntry) {
                $previous = $existingWorks->get((string) ($work['id'] ?? ''));
                $keepPrevious = $previous !== null
                    && $previous->work_type_id === $type?->id
                    && $previous->norm_is_manual
                    && $previous->norm_hours !== null;
                $work['normHours'] = $keepPrevious ? (string) $previous->norm_hours : null;
                $work['normIsManual'] = $keepPrevious;
                $work['normOverrideReason'] = $keepPrevious ? $previous->norm_override_reason : null;
                $work['timeNormId'] = '';

                if ($selection['status'] === TimeNormSelectionService::STATUS_CONFLICT) {
                    // Конфликт норм разрешает УК, Заказчику он не мешает.
                    $selection = [...$selection, 'norm' => null, 'scope' => null];
                }
            }

            if ($selection['status'] === TimeNormSelectionService::STATUS_CONFLICT && !$customerEntry) {
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
                Input::fail('Выбранная норма не является наиболее узкой для автомобиля', 'works');
            }

            $providedNorm = $work['normHours'] ?? null;
            $reason = trim((string) ($work['normOverrideReason'] ?? ''));
            $isManual = (bool) ($work['normIsManual'] ?? false)
                || ($providedNorm !== null && (
                    $norm?->hours === null
                    || bccomp((string) $providedNorm, (string) $norm->hours, 3) !== 0
                ));

            if ($isManual && ($providedNorm === null || $reason === '')) {
                $workName = $type?->name ?? trim((string) $work['name']);
                Input::fail(
                    'Для ручной нормы укажите положительное значение '
                    . 'и причину корректировки'
                    . ($workName !== '' ? " (работа «{$workName}»)" : ''),
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

            $id = (string) ($work['id'] ?? '');
            $keepId = $id !== '' && in_array($id, $existingIds, true) && !in_array($id, $usedIds, true);
            if ($keepId) {
                $usedIds[] = $id;
            }
            $comment = trim((string) ($work['comment'] ?? ''));
            $changeNote = trim((string) ($work['changeNote'] ?? ''));

            $rows[] = [
                ...($keepId ? ['uuid' => $id] : []),
                'repair_request_id' => $req->id,
                'comment'           => $comment !== '' ? $comment : null,
                'change_note'       => $changeNote !== '' ? $changeNote : null,
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
                'agent_discount_percent' => $agentDiscount,
            ];
        }

        RequestWork::query()->where('repair_request_id', $req->id)->delete();

        foreach ($rows as $row) {
            RequestWork::query()->create($row);
        }
    }

    private function resolveWorkType(RepairRequest $req, array $work): ?WorkType
    {
        if (empty($work['workTypeId'])) {
            return null;
        }

        $type = WorkType::query()
            ->where('uuid', $work['workTypeId'])
            ->where('tenant_id', $req->tenant_id)
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
    public function recalculatePlannedCompletion(RepairRequest $req): void
    {
        $stationSlot = (string) ($req->data['stationSlot'] ?? '');
        $desiredSlot = (string) ($req->data['slot'] ?? '');
        $slot = $stationSlot !== '' ? $stationSlot : $desiredSlot;

        if ($slot === '') {
            $req->planned_completion_at = null;

            return;
        }

        if ($req->workLines()->whereNull('norm_hours')->exists()) {
            $req->planned_completion_at = null;

            return;
        }

        $start = Input::localTime($slot);

        if ($start === null) {
            $req->planned_completion_at = null;

            return;
        }

        $normSeconds = $req->workLines()
            ->get()
            ->sum(static fn (RequestWork $work): int => (int) round(
                (float) ($work->norm_hours ?? 0)
                * (float) $work->quantity
                * 3600,
            ));

        $completion = $start
            ->addMinutes(30)
            ->addSeconds($normSeconds);

        $station = $req->assigned_station_id !== null
            ? Station::query()->find($req->assigned_station_id)
            : null;

        $req->planned_completion_at = $station === null
            ? $completion
            : $this->applyStationSchedule($completion, $station);
    }

    /**
     * Переносит расчётное завершение в допустимое рабочее окно СТО.
     * Если результат достиг времени закрытия, используется начало следующего
     * рабочего дня. Выходные дни пропускаются.
     */
    private function applyStationSchedule(CarbonImmutable $completion, Station $station, ): CarbonImmutable
    {
        $timezone = (string) config('sto.service_timezone');
        $local = $completion->setTimezone($timezone);
        $workingDays = array_values(array_unique(array_map(
            static fn (mixed $day): int => (int) $day,
            $station->working_days ?? range(1, 7),
        )));
        $workdayStart = $this->minutesFromTime((string) ($station->workday_start ?? '10:00'), );
        $workdayEnd = $this->minutesFromTime((string) ($station->workday_end ?? '20:00'), );

        if (!in_array($local->dayOfWeekIso, $workingDays, true)) {
            return $this->nextStationWorkday($local, $workingDays, $workdayStart)->utc();
        }

        $minutes = ($local->hour * 60) + $local->minute;

        if ($minutes < $workdayStart) {
            return $this->atMinuteOfDay($local, $workdayStart)->utc();
        }

        if ($minutes >= $workdayEnd) {
            return $this->nextStationWorkday($local, $workingDays, $workdayStart, )->utc();
        }

        return $local->utc();
    }

    private function nextStationWorkday(CarbonImmutable $date, array $workingDays, int $workdayStart, ): CarbonImmutable
    {
        $candidate = $date->addDay()->startOfDay();

        for ($attempt = 0; $attempt < 7; $attempt++) {
            if (in_array($candidate->dayOfWeekIso, $workingDays, true)) {
                return $this->atMinuteOfDay($candidate, $workdayStart);
            }

            $candidate = $candidate->addDay();
        }

        return $this->atMinuteOfDay($candidate, $workdayStart);
    }

    private function atMinuteOfDay(CarbonImmutable $date, int $minuteOfDay): CarbonImmutable
    {
        return $date->setTime(intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }

    private function minutesFromTime(string $value): int
    {
        $num1 = ((int) substr($value, 0, 2) * 60);
        $num2 = (int) substr($value, 3, 2);

        return $num1 + $num2;
    }

    /* ─────────────────── Вложения ─────────────────── */

    /**
     * @param array<int, string> $ids
     *
     * @return array<int, Attachment>
     */
    public function attachmentIds(
        RepairRequest $req,
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
            ->where('repair_request_id', $req->id)
            ->whereIn('uuid', $ids);

        if ($purpose !== null) {
            $query->where(
                'purpose',
                $purpose instanceof AttachmentPurpose ? $purpose->value : $purpose,
            );
        }

        if ($current) {
            $periodId = $req->periods()
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
    public function stationDecision(User $user, StationDecisionDto $dto): RepairRequest
    {
        $userDto = UserDto::fromUserModel($user);

        return $this->mutate(
            $user,
            $dto->requestKey,
            $dto->revision,
            'station_' . $dto->decision->value,
            fn(RepairRequest $req) => $this->handleStationDecision($req, $userDto, $dto)
        );
    }

    private function handleStationDecision(RepairRequest $req, UserDto $userDto, StationDecisionDto $dto): array
    {
        $this->flag($req, $userDto, 'canStationDecide');

        // Валидация комментария (перенесена из контроллера)
        if ($dto->decision === ApprovalDecision::Declined && trim($dto->comment) === '') {
            Input::fail('Укажите причину отказа от выполнения заявки', 'comment');
        }

        if ($dto->decision === ApprovalDecision::Declined) {
            $declined = $req->data['declinedStationIds'] ?? [];
            $declined[] = $req->stationRecord?->uuid;
            $req->data = array_replace($req->data, ['declinedStationIds' => array_values(array_unique(array_filter($declined)))]);
        }

        $from = $req->stage;
        $desiredSlot = (string) ($req->data['slot'] ?? '');

        if ($dto->decision === ApprovalDecision::Confirmed) {
            $req->data = array_replace($req->data, ['stationSlot' => $desiredSlot]);
            $this->recalculatePlannedCompletion($req);
        }

        if ($dto->decision === ApprovalDecision::Rescheduled) {
            $stationSlot = (string) $dto->stationSlot;

            if ($stationSlot === '' || $stationSlot === $desiredSlot) {
                Input::fail('Выберите другое время визита', 'stationSlot');
            }

            $this->validateLocalTimeFields(['stationSlot' => $stationSlot]);
            $req->data = array_replace($req->data, ['stationSlot' => $stationSlot]);
            $this->recalculatePlannedCompletion($req);
        }

        $changes = $this->estimateChanges($req);
        $estimateChanged = $this->estimateChangedSinceReview($req);

        if ($dto->decision !== ApprovalDecision::Declined && !$estimateChanged) {
            $this->validateManagerReviewStage($req);
        }

        $to = match (true) {
            $dto->decision === ApprovalDecision::Declined => RepairStage::ManagerRouting,
            $estimateChanged => RepairStage::ManagerRouting,
            default => RepairStage::CustomerApproval,
        };

        $snapshot = $this->buildTransitionSnapshot($req);

        if ($to === RepairStage::ManagerRouting) {
            if ($estimateChanged) {
                // Смета, которую УК пропустила в СТО (для ЗР, ушедших в СТО до
                // появления отдельного снимка).
                $this->rememberManagerApprovedFromBaseline($req);
            }
            $this->captureReviewBaseline(
                $req,
                $estimateChanged ? ReviewContext::StationChanges : ReviewContext::StationDeclined,
            );
        } else {
            $req->review_context = ReviewContext::Initial;
        }

        $this->moveToStage($req, $userDto->userId, $to);
        $req->data = array_replace($req->data, ['routeComment' => '']);

        return [
            'comment' => $dto->decision->label() . ($dto->comment !== '' ? ': ' . $dto->comment : ''),
            'details' => [
                'decision' => $dto->decision->value,
                'from'     => $from->value,
                'to'       => $to->value,
                'changes'  => $changes,
            ],
            'after' => function (RequestEvent $event) use ($req, $userDto, $from, $dto, $snapshot, $to, $changes): void {
                $approvalDto = ApprovalDto::fromRequest(
                    request: $req,
                    eventId: $event->id,
                    userId: $userDto->userId,
                    stage: $from,
                    decision: $dto->decision,
                    comment: $dto->comment,
                    snapshot: $snapshot,
                );
                $this->createApproval($approvalDto);

                if ($to === RepairStage::ManagerRouting) {
                    $this->notifyManagerReviewRequired($req, $event->id, $dto->decision->label(), $changes);
                } else {
                    $this->notifyCustomerFinalApproval($req, $event->id);
                }

                if ($dto->decision === ApprovalDecision::Rescheduled && $to === RepairStage::CustomerApproval) {
                    $this->notifySlotChanged($req, $event->id);
                }
            },
        ];
    }

    /**
     * Сохраняет финальное решение Заказчика. Согласованная заявка переходит в
     * ожидание автомобиля, новое время возвращает её в УК.
     *
     * @param array<string, mixed> $body
     *
     * @throws Throwable
     */
    public function customerSlotDecision(User $user, CustomerSlotDecisionDto $dto): RepairRequest
    {
        return $this->mutate(
            $user,
            $dto->requestKey,
            $dto->revision,
            'customer_slot_' . $dto->decision->value,
            fn(RepairRequest $req) => $this->handleCustomerSlotDecision($req, $user, $dto)
        );
    }

    private function handleCustomerSlotDecision(RepairRequest $req, User $user, CustomerSlotDecisionDto $dto): array
    {
        $userDto = UserDto::fromUserModel($user);
        $this->flag($req, $userDto, 'canCustomerSlotDecide');

        $stationSlot = (string) ($req->data['stationSlot'] ?? '');

        if ($dto->decision === ApprovalDecision::Accepted) {
            $this->assertHasWorks($req);
            if ($stationSlot === '') {
                Input::fail('СТО не указала новое время визита');
            }
            $slot = $stationSlot;
        } else {
            $slot = (string) $dto->slot;
            if ($slot === '' || $slot === $stationSlot) {
                Input::fail('Выберите другое время визита', 'slot');
            }
            $this->validateLocalTimeFields(['slot' => $slot]);
            $stationSlot = '';
        }

        $req->data = array_replace($req->data, [
            'slot'         => $slot,
            'stationSlot'  => $stationSlot,
            'routeComment' => '',
        ]);

        $this->recalculatePlannedCompletion($req);
        $snapshot = $this->buildTransitionSnapshot($req);
        $from = $req->stage;

        $to = $dto->decision === ApprovalDecision::Accepted
            ? RepairStage::WaitingVehicle
            : RepairStage::ManagerRouting;

        if ($dto->decision === ApprovalDecision::Accepted) {
            $this->approveCurrentEstimate($req);
        } else {
            $this->captureReviewBaseline($req, ReviewContext::CustomerSlot);
        }

        $this->moveToStage($req, $userDto->userId, $to);

        $label = $dto->decision === ApprovalDecision::Accepted
            ? 'Заказчик согласовал время СТО'
            : 'Заказчик предложил другое время';

        return [
            'comment' => $label . ($dto->comment !== '' ? ': ' . $dto->comment : ''),
            'details' => [
                'decision' => $dto->decision->value,
                'from'     => $from->value,
                'to'       => $to->value,
                'slot'     => $slot,
            ],
            'after' => function (RequestEvent $event) use ($req, $userDto, $from, $dto, $snapshot, $to): void {
                $approvalDto = ApprovalDto::fromRequest(
                    request: $req,
                    eventId: $event->id,
                    userId: $userDto->userId,
                    stage: $from,
                    decision: $dto->decision,
                    comment: $dto->comment,
                    snapshot: $snapshot,
                );
                $this->createApproval($approvalDto);

                if ($to === RepairStage::WaitingVehicle) {
                    $this->notifyVehicleExpected($req, $event->id);

                    DB::afterCommit(function () use ($req): void {
                        RepairRequestWaitingVehicle::dispatch($req);
                    });
                } else {
                    $this->notifyManagerReviewRequired($req, $event->id, ReviewContext::CustomerSlot->label());
                }
            },
        ];
    }

    /**
     * Фиксирует неизменяемый акт приёма ТС и переводит заявку в работу.
     *
     * @param array<string, mixed> $body
     *
     * @throws Throwable
     */
    public function acceptVehicle(User $user, AcceptVehicleDto $dto): RepairRequest
    {
        return $this->mutate(
            $user,
            $dto->requestKey,
            $dto->revision,
            'vehicle_acceptance',
            fn(RepairRequest $req) => $this->handleVehicleAcceptance($req, $user, $dto)
        );
    }

    /**
     * Вся бизнес-логика приемки ТС. Без вложенности.
     */
    private function handleVehicleAcceptance(RepairRequest $req, User $user, AcceptVehicleDto $dto): array
    {
        $userDto = UserDto::fromUserModel($user);
        $this->flag($req, $userDto, 'canAcceptVehicle');

        if ($req->acceptanceAct()->exists()) {
            throw new ApiException(409, 'ACCEPTANCE_ACT_LOCKED', 'Акт приёма уже сохранён и недоступен для изменения');
        }

        // Приём ТС от Заказчика на СТО фиксируется только подписанным
        // сторонами листом приёма-передачи ТС (скан или фото).
        if ($dto->mode !== 'uploaded' || $dto->attachmentId === null) {
            Input::fail('Загрузите подписанный лист приёма-передачи ТС', 'attachmentId');
        }
        $attachment = $this->attachmentIds(
            $req,
            [$dto->attachmentId],
            AttachmentPurpose::AcceptanceAct,
            true,
        )[0];

        $act = VehicleAcceptanceAct::query()->create([
            'repair_request_id' => $req->id,
            'station_id'        => $req->assigned_station_id,
            'created_by'        => $userDto->userId,
            'attachment_id'     => $attachment?->id,
            'number'            => $req->numberWithPrefix('APP') . '/1',
            'mode'              => 'uploaded',
            'vehicle_snapshot'  => [
                'make'  => $req->vehicleRecord?->make,
                'model' => $req->vehicleRecord?->model,
                'vin'   => $req->vehicleRecord?->vin,
                'plate' => $req->vehicleRecord?->plate,
            ],
            'contact_snapshot'  => $req->contact_snapshot,
            'driver_snapshot'   => $req->driver_snapshot,
            'mileage'           => $dto->mileage,
            'fuel_level'        => $dto->fuelLevel,
            'condition'         => $dto->condition ?? '',
            'defect_types'      => $dto->defectTypes,
            'diagram_marks'     => $dto->diagramMarks,
            'locked_at'         => now(),
        ]);

        // Этап не меняется: СТО переводит ЗР «В работу» кнопкой
        // «Далее по маршруту» после загрузки листа приёма-передачи.
        return [
            'comment' => 'Загружен подписанный лист приёма-передачи ТС ' . $act->number . ' (приём ТС от Заказчика на СТО)',
            'details' => [
                'acceptanceActId' => $act->uuid,
            ],
            'after' => function (RequestEvent $event) use ($attachment): void {
                $attachment->update(['request_event_id' => $event->id]);
            },
        ];
    }

    /**
     * «Принять ЗЧ от УК» (СТО, этап «Ожидание ТС на СТО»): ЗЧ ЗР перемещаются
     * со склада УК на «Склад от УК» СТО документом Перемещение (PRM-).
     */
    public function acceptManagerParts(User $user, string $key, int $revision): RepairRequest
    {
        $userDto = UserDto::fromUserModel($user);

        return $this->mutate(
            $user,
            $key,
            $revision,
            'manager_parts_accepted',
            function (RepairRequest $req) use ($userDto, $user): array {
                $this->flag($req, $userDto, 'canAcceptManagerParts');
                $req->loadMissing('stationRecord');
                $doc = app(StockService::class)->transferFromManager($req, $user);

                return [
                    'comment' => 'ЗЧ от УК приняты на склад СТО «' . Warehouse::FROM_MANAGER_NAME . '», Перемещение ' . $doc->number,
                    'details' => ['transferId' => $doc->uuid, 'number' => $doc->number],
                ];
            }
        );
    }

    public function transition(User $user, TransitionDto $dto): RepairRequest
    {
        return $this->mutate(
            $user,
            $dto->requestKey,
            $dto->revision,
            $dto->action->value,
            fn(RepairRequest $req) => $this->handleTransition($req, $user, $dto)
        );
    }

    private function handleTransition(RepairRequest $req, User $user, TransitionDto $dto): array
    {
        $this->assertTransitionAllowed($req, $user, $dto->action, $dto->comment, $dto->attachmentIds);

        $files = $this->attachmentIds(
            $req,
            $dto->attachmentIds,
            AttachmentPurpose::Evidence,
            true,
        );

        $from = $req->stage;
        $snapshot = $this->buildTransitionSnapshot($req);

        if ($dto->action === TransitionAction::Next) {
            $this->validateNext($req);
        }

        $to = $this->transitionTarget($req, $from, $dto->action);
        $this->assertStationNotDeclined($req, $from, $to, $dto->action, $dto->confirmDeclinedStation);

        $reviewContext = $req->review_context;
        $changes = $from === RepairStage::ManagerRouting ? $this->estimateChanges($req) : [];

        $this->prepareStationSchedule($req, $dto->action, $from, $to);
        $this->completeOrderIfNeeded($req, $dto->action, $from, $dto->comment);
        $this->handleReviewContext($req, $from, $to, $dto->action, $reviewContext);

        $isReadyForIssue = $dto->action === TransitionAction::Next
            && $from === RepairStage::Ready
            && $to === RepairStage::Issued;

        $this->moveToStage($req, $user->id, $to);
        $req->data = array_replace($req->data, ['routeComment' => '']);

        if ($dto->action === TransitionAction::Return && $req->stage->value <= RepairStage::RepairInProgress->value) {
            $req->repair_completed_at = null;
        }

        // Закрытие ЗР: акт передачи ТС при готовности ТС к выдаче,
        // комплект документов УК после выдачи ТС.
        if ($dto->action === TransitionAction::Next && $to === RepairStage::Ready) {
            app(StockService::class)->writeOffRequest($req);
            $this->closingDocuments->generateTransferAct($req, $user);
        }
        if ($dto->action === TransitionAction::Return && $to->isBefore(RepairStage::Ready)) {
            app(StockService::class)->cancelRequestWriteOff($req);
        }
        if ($dto->action === TransitionAction::Next && $to === RepairStage::Issued) {
            $this->closingDocuments->generateClosingDocuments($req, $user);
        }

        $context = new TransitionContext(
            from: $from,
            to: $to,
            action: $dto->action,
            decision: $dto->action === TransitionAction::Next ? ApprovalDecision::Approved : ApprovalDecision::Returned,
            comment: $dto->comment,
            snapshot: $snapshot,
            files: $files,
            changes: $changes,
            reviewContext: $req->review_context?->value ?? '',
            isReadyForIssue: $isReadyForIssue,
        );

        return [
            'comment' => $dto->action->label() . ': '
                . Workflow::STAGES[$from->value] . ' → '
                . Workflow::STAGES[$req->stage->value]
                . ($dto->comment !== '' ? '. ' . $dto->comment : ''),
            'details' => [
                'from'    => $from->value,
                'to'      => $req->stage->value,
                'changes' => $changes,
            ],
            'after'   => $this->transitionAfter($req, $user, $context),
        ];
    }

    private function assertTransitionAllowed(
        RepairRequest $req,
        User $user,
        TransitionAction $transition,
        string $comment,
        array $attachmentIds,
    ): void {
        $userDto = UserDto::fromUserModel($user);
        $this->flag(
            $req,
            $userDto,
            $transition === TransitionAction::Next ? 'canAdvance' : 'canReturn',
        );

        $isInitialSubmission = $transition === TransitionAction::Next
            && $req->stage === RepairStage::Draft
            && $user->role === UserRole::Customer;

        $isManagerDecision = $user->role === UserRole::Manager;

        // Выдача ТС (6 → 7) подтверждается подписанным актом передачи ТС.
        // Приём ТС на СТО (4 → 5) подтверждается подписанным листом приёма-передачи.
        $isVehicleHandover = $transition === TransitionAction::Next
            && in_array($req->stage, [RepairStage::WaitingVehicle, RepairStage::Ready], true);

        $requiresComment = (!$isInitialSubmission && !$isManagerDecision && !$isVehicleHandover) || ($isManagerDecision && $req->review_context === ReviewContext::StationChanges && $transition === TransitionAction::Return);

        if ($requiresComment && trim($comment) === '') {
            Input::fail('Укажите комментарий к решению');
        }

        // 👇 Проверяем массив вложений, который передали
        if ($isManagerDecision && $attachmentIds !== []) {
            Input::fail('Для решения УК вложения не используются');
        }
    }

    private function assertStationNotDeclined(
        RepairRequest $req,
        RepairStage $from,
        RepairStage $to,
        TransitionAction $transition,
        bool $confirmDeclinedStation,
    ): void {
        if (
            $transition === TransitionAction::Next
                && $from === RepairStage::ManagerRouting
                && $to === RepairStage::StationApproval
                && in_array($req->stationRecord?->uuid, $req->data['declinedStationIds'] ?? [], true)
                && !$confirmDeclinedStation
        ) {
            throw new ApiException(
                409,
                'STATION_PREVIOUSLY_DECLINED',
                'Это СТО ранее отказалось от выполнения данной заявки. Подтвердите повторное направление.'
            );
        }
    }

    private function handleReviewContext(
        RepairRequest $req,
        RepairStage $from,
        RepairStage $to,
        TransitionAction $transition,
        ReviewContext $reviewContext,
    ): void {
        if ($from === RepairStage::ManagerRouting && $to === RepairStage::StationApproval) {
            if ($reviewContext === ReviewContext::StationChanges) {
                // УК вернула правки СТО на доработку. СТО сравнивает смету с той,
                // что УК согласовала раньше: «Подтвердить» без исправлений снова
                // отправит изменения в УК, а не Заказчику.
                $this->restoreManagerApprovedBaseline($req);

                return;
            }

            $this->captureReviewBaseline($req, ReviewContext::Initial);
            $this->rememberManagerApproved($req);
        }

        if ($transition === TransitionAction::Next && $from === RepairStage::ManagerRouting && $to === RepairStage::CustomerApproval) {
            $req->review_context = ReviewContext::Initial;
            $this->rememberManagerApproved($req);
        }
    }

    private function transitionTarget(
        RepairRequest $req,
        RepairStage $from,
        TransitionAction $action,
    ): RepairStage {
        if ($from === RepairStage::ManagerRouting) {
            if ($action === TransitionAction::Return) {
                return $req->review_context === ReviewContext::StationChanges
                    ? RepairStage::StationApproval
                    : RepairStage::Draft;
            }

            if ($req->review_context === ReviewContext::StationChanges) {
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
        RepairRequest $req,
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

        $req->data = array_replace($req->data, [
            'stationSlot' => (string) (
                ($req->data['stationSlot'] ?? '')
                ?: ($req->data['slot'] ?? '')
            ),
        ]);
        $this->recalculatePlannedCompletion($req);
    }

    private function moveToStage(RepairRequest $req, int $userId, RepairStage $to): void
    {
        $now = now();

        $req->periods()
            ->whereNull('exited_at')
            ->firstOrFail()
            ->update(['exited_at' => $now]);

        $req->stage = $to;
        if ($to === RepairStage::Closed && preg_match('/^\d{1,9}$/', (string) ($req->data['mileage'] ?? ''))) {
            $vehicle = Vehicle::query()->whereKey($req->vehicle_id)->lockForUpdate()->first();
            $mileage = (int) $req->data['mileage'];
            if ($vehicle !== null && $mileage > (int) ($vehicle->baseline_service_mileage ?? -1)) {
                $vehicle->forceFill([
                    'baseline_service_mileage' => $mileage,
                    'baseline_service_at' => $now,
                ])->save();
            }
        }

        RequestStatusPeriod::query()->create([
            'repair_request_id' => $req->id,
            'stage'             => $to->value,
            'entered_at'        => $now,
            'exited_at'         => $to === RepairStage::Closed ? $now : null,
            'actor_id'          => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransitionSnapshot(RepairRequest $req): array
    {
        return [
            'total'               => $this->repairRequestCalculator->total($req),
            'works'               => $req->workLines()->get()->toArray(),
            'parts'               => $req->parts()->get()->toArray(),
            'plannedCompletionAt' => $req->planned_completion_at?->toISOString(),
            'stationSlot'         => $req->data['stationSlot'] ?? '',
            'estimateVersion'     => $req->estimate_version,
        ];
    }

    private function completeOrderIfNeeded(
        RepairRequest $req,
        TransitionAction $action,
        RepairStage $from,
        string $comment,
    ): void {
        if ($action === TransitionAction::Next && $from === RepairStage::RepairInProgress) {
            $this->completeWorkOrder($req, $comment);
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
    private function transitionAfter(RepairRequest $req, User $user, TransitionContext $context): callable
    {
        $userDto = UserDto::fromUserModel($user);
        return function (RequestEvent $event) use ($req, $userDto, $context): void {
            $dto = ApprovalDto::fromRequest(
                request: $req,
                eventId: $event->id,
                userId: $userDto->userId,
                stage: $context->from,
                decision: $context->action === TransitionAction::Next ? ApprovalDecision::Approved : ApprovalDecision::Returned,
                comment: $context->comment,
                snapshot: $context->snapshot,
            );
            $this->createApproval($dto);

            $ids = array_map(static fn (Attachment $file) => $file->id, $context->files);

            Attachment::query()
                ->whereIn('id', $ids)
                ->update(['request_event_id' => $event->id]);

            if ($context->from === RepairStage::ManagerRouting) {
                if ($req->stage === RepairStage::CustomerApproval) {
                    $this->notifyStationEstimateApproved($req, $event->id);
                    $this->notifyCustomerFinalApproval($req, $event->id);
                } elseif ($req->stage === RepairStage::StationApproval && $context->reviewContext === ReviewContext::StationChanges->value) {
                    $this->notifyStationEstimateChanged(
                        $req,
                        $event->id,
                        [...$context->changes, 'Комментарий УК: ' . $context->comment]
                    );
                }
            }

            if (
                $context->action === TransitionAction::Return
                && $context->from === RepairStage::WaitingVehicle
                && $req->stage === RepairStage::CustomerApproval
            ) {
                $this->notifyCustomerReturnedFromWaitingVehicle($req, $event->id, $context->comment);
            }

            if (
                $context->action === TransitionAction::Next
                && $context->from === RepairStage::WaitingVehicle
                && $req->stage === RepairStage::RepairInProgress
            ) {
                $this->notifyWorkStarted($req, $event->id);
                $act = $req->acceptanceAct()->first();
                if ($act !== null) {
                    DB::afterCommit(function () use ($req, $act): void {
                        VehicleAccepted::dispatch($req, $act);
                    });
                }
            }

            if ($context->isReadyForIssue) {
                DB::afterCommit(function () use ($req): void {
                    ClosingDocumentsRequested::dispatch($req);
                });
            }
        };
    }

    public function createApproval(ApprovalDto $dto): void
    {
        RequestApproval::query()->create([
            'repair_request_id' => $dto->requestId,
            'request_event_id'  => $dto->eventId,
            'actor_id'          => $dto->actorId,
            'stage'             => $dto->stage->value,
            'decision'          => $dto->decision,
            'comment'           => $dto->comment,
            'estimate_version'  => $dto->estimateVersion,
            'snapshot'          => $dto->snapshot,
        ]);
    }

    private function notifyWorkStarted(RepairRequest $req, int $eventId): void
    {
        $recipients = User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where(function ($query) use ($req): void {
                $query
                    ->where(function ($customer) use ($req): void {
                        $customer
                            ->where('role', UserRole::Customer->value)
                            ->where('organization_id', $req->customer_organization_id);
                    })
                    ->orWhere(function ($manager) use ($req): void {
                        $manager
                            ->where('role', UserRole::Manager->value)
                            ->whereHas(
                                'organization',
                                fn ($organization) => $organization->where('tenant_id', $req->tenant_id),
                            );
                    });
            })
            ->get();

        $slot = (string) ($req->data['stationSlot'] ?? $req->data['slot'] ?? '');
        $message = 'СТО подтвердила начало работ по заявке ' . $req->number;

        if ($slot !== '') {
            $message .= '. Подтверждённое время: ' . $slot;
        }

        foreach ($recipients as $recipient) {
            RequestNotification::query()->firstOrCreate(
                [
                    'user_id'          => $recipient->id,
                    'request_event_id' => $eventId,
                    'kind'             => 'work_started',
                ],
                [
                    'repair_request_id' => $req->id,
                    'title'             => 'Работы по ' . $req->number . ' начаты',
                    'message'           => $message,
                ],
            );
        }
    }

    private function notifySlotChanged(RepairRequest $req, int $eventId): void
    {
        $recipients = User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Customer->value)
            ->where('organization_id', $req->customer_organization_id)
            ->get();
        $slot = (string) ($req->data['stationSlot'] ?? '');

        foreach ($recipients as $recipient) {
            RequestNotification::query()->firstOrCreate(
                [
                    'user_id'          => $recipient->id,
                    'request_event_id' => $eventId,
                    'kind'             => 'station_slot_changed',
                ],
                [
                    'repair_request_id' => $req->id,
                    'title'             => 'Время посещения СТО изменено',
                    'message'           => "По заявке {$req->number} СТО предложила новое время: $slot",
                ],
            );
        }
    }

    /**
     * Сохраняет снимок сметы, относительно которого следующий участник
     * согласования будет видеть изменения.
     */
    private function captureReviewBaseline(RepairRequest $req, ReviewContext $context): void
    {
        $req->review_context = $context;
        $req->review_baseline_version = $req->estimate_version;
        $req->review_baseline_snapshot = $this->estimateSnapshot($req);
    }

    /** Текущая смета пропущена УК (в СТО или Заказчику). */
    private function rememberManagerApproved(RepairRequest $req): void
    {
        $req->manager_approved_estimate_version = $req->estimate_version;
        $req->manager_approved_estimate_snapshot = $this->estimateSnapshot($req);
    }

    /**
     * Снимок, с которым СТО сравнивала смету, — это смета, пропущенная УК.
     * Нужен для ЗР, переданных в СТО до появления отдельного снимка.
     */
    private function rememberManagerApprovedFromBaseline(RepairRequest $req): void
    {
        if ($req->manager_approved_estimate_snapshot !== null || !is_array($req->review_baseline_snapshot)) {
            return;
        }

        $req->manager_approved_estimate_version = $req->review_baseline_version;
        $req->manager_approved_estimate_snapshot = $req->review_baseline_snapshot;
    }

    /** После возврата УК базой сравнения для СТО становится смета, согласованная УК. */
    private function restoreManagerApprovedBaseline(RepairRequest $req): void
    {
        $req->review_context = ReviewContext::StationChanges;

        if (!is_array($req->manager_approved_estimate_snapshot)) {
            // Согласованной сметы нет — правки СТО в любом случае идут в УК.
            $req->review_baseline_version = 0;
            $req->review_baseline_snapshot = null;

            return;
        }

        $req->review_baseline_version = (int) $req->manager_approved_estimate_version;
        $req->review_baseline_snapshot = $req->manager_approved_estimate_snapshot;
    }

    /**
     * Изменилась ли смета относительно снимка, зафиксированного при передаче
     * на согласование. Сравнивается итоговый состав работ и запчастей, а не
     * история правок: если СТО добавила позиции и перенесла их в
     * Рекомендации, смета не изменилась и повторное согласование не нужно.
     */
    public function estimateChangedSinceReview(RepairRequest $req): bool
    {
        if ($req->estimate_version === $req->review_baseline_version) {
            return false;
        }

        $baseline = $req->review_baseline_snapshot;

        if (!is_array($baseline) || !in_array($req->stage, [RepairStage::ManagerRouting, RepairStage::StationApproval], true)) {
            return true;
        }

        $current = $this->estimateSnapshot($req);

        return self::normalizedSnapshot($baseline) !== self::normalizedSnapshot($current);
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, array<string, array<string, string|null>>>
     */
    private static function normalizedSnapshot(array $snapshot): array
    {
        $normalized = [];

        foreach (['works', 'parts'] as $kind) {
            $lines = [];
            foreach ($snapshot[$kind] ?? [] as $index => $line) {
                if (!is_array($line)) {
                    continue;
                }
                $values = [];
                foreach ($line as $field => $value) {
                    $values[$field] = is_numeric($value)
                        ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.')
                        : ($value === null || $value === '' ? null : (string) $value);
                }
                ksort($values);
                $lines[(string) ($line['id'] ?? "#$index")] = $values;
            }
            ksort($lines);
            $normalized[$kind] = $lines;
        }

        return $normalized;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function estimateSnapshot(RepairRequest $req): array
    {
        $parts = $req->parts()->orderBy('id')->get();
        $supplierNames = Supplier::query()
            ->whereIn('id', $parts->pluck('supplier_id')->filter()->all())
            ->pluck('name', 'id');

        return [
            'works' => $req->workLines()
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
    private function estimateChanges(RepairRequest $req): array
    {
        $before = is_array($req->review_baseline_snapshot)
            ? $req->review_baseline_snapshot
            : ['works' => [], 'parts' => []];
        $after = $this->estimateSnapshot($req);

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

    private function approveCurrentEstimate(RepairRequest $req): void
    {
        foreach ($req->parts()->get() as $part) {
            $part->approved_version = $req->estimate_version;

            if ($part->status === RequestPartStatus::Proposed) {
                $part->status = RequestPartStatus::Approved;
            }

            $part->save();
        }
    }

    /**
     * @param array<int, string> $changes
     */
    private function notifyManagerReviewRequired(RepairRequest $req, int $eventId, string $label, array $changes = []): void
    {
        $message = "$label по заявке {$req->number}.";

        if ($changes !== []) {
            $message .= ' Изменения: ' . implode('; ', $changes);
        }

        $dto = NotificationDto::fromRequest(
            $this->managerUsers($req->tenant_id),
            $req->id,
            $eventId,
            'manager_review_required',
            'Требуется согласование УК',
            $message,
        );
        $this->notifyUsers($dto);
    }

    private function notifyCustomerFinalApproval(RepairRequest $req, int $eventId): void {
        $dto = NotificationDto::fromRequest(
            $this->customerUsers($req),
            $req->id,
            $eventId,
            'customer_final_approval',
            'СТО готова принять автомобиль',
            "Работы, запчасти и время визита по заявке {$req->number} согласованы. Подтвердите финальную заявку.",
        );

        $this->notifyUsers($dto);
    }

    private function notifyCustomerReturnedFromWaitingVehicle(RepairRequest $req, int $eventId, string $comment): void
    {
        $dto = NotificationDto::fromRequest(
            $this->customerUsers($req),
            $req->id,
            $eventId,
            'request_returned',
            'СТО вернула заявку на согласование',
            "СТО вернула заявку {$req->number} с этапа «Ожидание ТС на СТО». Комментарий СТО: {$comment}. Подтвердите финальную заявку или предложите другое время.",
        );

        $this->notifyUsers($dto);
    }

    private function notifyVehicleExpected(RepairRequest $req, int $eventId): void
    {
        $dto = NotificationDto::fromRequest(
            $this->stationUsers($req),
            $req->id,
            $eventId,
            'vehicle_expected',
            'Ожидается автомобиль',
            "Заказчик подтвердил заявку {$req->number}. После прибытия оформите акт приёма ТС.",
        );
        $this->notifyUsers($dto);
    }

    private function notifyStationEstimateApproved(RepairRequest $req, int $eventId): void
    {
        $dto = NotificationDto::fromRequest(
            $this->stationUsers($req),
            $req->id,
            $eventId,
            'station_estimate_approved',
            'Изменения согласованы УК',
            "УК согласовала работы и запчасти по заявке {$req->number}.",
        );
        $this->notifyUsers($dto);
    }

    /**
     * @param array<int, string> $changes
     */
    private function notifyStationEstimateChanged(RepairRequest $req, int $eventId, array $changes): void
    {
        $dto = NotificationDto::fromRequest(
            $this->stationUsers($req),
            $req->id,
            $eventId,
            'station_estimate_changed',
            'УК изменила работы или запчасти',
            "По заявке {$req->number} изменено: " . implode('; ', $changes),
        );
        $this->notifyUsers($dto);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function managerUsers(int $tenantId): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Manager->value)
            ->whereHas(
                'organization',
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function customerUsers(RepairRequest $req): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Customer->value)
            ->where('organization_id', $req->customer_organization_id)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function stationUsers(RepairRequest $req): Collection
    {
        $organizationId = $req->stationRecord?->organization_id;

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
    public function notifyUsers(NotificationDto $dto): void
    {
        foreach ($dto->recipients as $recipient) {
            RequestNotification::query()->firstOrCreate(
                [
                    'user_id'          => $recipient->id,
                    'request_event_id' => $dto->eventId,
                    'kind'             => $dto->kind,
                ],
                [
                    'repair_request_id' => $dto->requestId,
                    'title'             => $dto->title,
                    'message'           => $dto->message,
                ],
            );
        }
    }

    /* ─────────────────── Валидация переходов ─────────────────── */

    private function validateNext(RepairRequest $req): void
    {
        $stage = $req->stage;

        if ($stage === RepairStage::Draft) {
            $this->validateDraftStage($req, false);
        }

        if ($stage === RepairStage::ManagerRouting) {
            $this->validateDraftStage($req, true);
        }

        if ($stage === RepairStage::ManagerRouting && !$req->assigned_station_id) {
            Input::fail('Назначьте СТО');
        }

        if ($stage === RepairStage::ManagerRouting) {
            $this->validateManagerReviewStage($req);
        }

        if ($stage === RepairStage::WaitingVehicle && app(StockService::class)->requiresManagerParts($req)) {
            Input::fail('Примите ЗЧ от УК: нажмите «Принять ЗЧ от УК» на вкладке «Работы и ЗЧ»');
        }

        if ($stage === RepairStage::WaitingVehicle && !$req->acceptanceAct()->exists()) {
            Input::fail('Загрузите подписанный лист приёма-передачи ТС в блок «Приём ТС от Заказчика на СТО»');
        }

        if ($stage === RepairStage::RepairInProgress) {
            if (app(WorkChangeService::class)->hasPendingChanges($req)) {
                Input::fail('Изменения работ и запчастей не согласованы. Отправьте их на согласование или отмените.');
            }

            if (!$req->acceptanceAct()->exists()) {
                Input::fail('Сначала оформите акт приёма ТС');
            }

            $this->validateEstimateStage($req);
            $this->validatePartsReceivedStage($req);
        }

        if (
            in_array($stage, [RepairStage::Ready, RepairStage::Issued], true)
            && !$req->workOrder()->exists()
        ) {
            Input::fail('Заказ-наряд ещё не сформирован');
        }

        if ($stage === RepairStage::Ready && !$this->closingDocuments->isTransferActSigned($req)) {
            Input::fail('Загрузите подписанный лист приёма-передачи ТС в блок «Приём ТС от СТО на Заказчика» и подтвердите, что лицо, которому передаётся ТС, сверено с действующей доверенностью');
        }

        if ($stage === RepairStage::Issued && !$this->closingDocuments->hasClosingDocuments($req)) {
            Input::fail('Сформируйте документы по заявке');
        }

        if ($stage === RepairStage::CustomerPayment && !$this->closingDocuments->isPaid($req, RequestDocumentType::CustomerInvoice)) {
            Input::fail('Отметьте оплату Заказчика');
        }

        if ($stage === RepairStage::StationPayment && !$this->closingDocuments->isPaid($req, RequestDocumentType::StationInvoice)) {
            Input::fail('Отметьте оплату в пользу СТО');
        }
    }

    private function validateDraftStage(
        RepairRequest $req,
        bool $requireVisitTime,
    ): void {
        if (!$req->vehicle_id) {
            Input::fail('Выберите автомобиль из перечня вашей организации');
        }

        if (!$req->reason_id) {
            Input::fail('Выберите причину обращения');
        }

        if (($req->data['mileage'] ?? '') === '') {
            Input::fail('Укажите текущий пробег');
        }

        $hasActiveContact = $req->contact_id !== null
            && VehicleContact::query()
                ->whereKey($req->contact_id)
                ->where('vehicle_id', $req->vehicle_id)
                ->where('kind', PersonKind::Contact->value)
                ->where('active', true)
                ->exists();

        $contactSnapshot = $req->contact_snapshot;
        $hasStoredContact = is_array($contactSnapshot)
            && trim((string) ($contactSnapshot['fullName'] ?? '')) !== ''
            && trim((string) ($contactSnapshot['phone'] ?? '')) !== '';

        if (!$hasActiveContact && !$hasStoredContact) {
            Input::fail('Выберите контактное лицо');
        }

        if ($requireVisitTime && ($req->data['slot'] ?? '') === '') {
            Input::fail('Укажите желаемое время визита');
        }
    }

    private function validateEstimateStage(RepairRequest $req): void
    {
        if (!$req->planned_completion_at) {
            Input::fail('Укажите плановую дату и время завершения');
        }

        if (!$req->workLines()->exists()) {
            Input::fail('Заполните перечень работ');
        }

        foreach ($req->workLines()->get() as $work) {
            if (trim((string) $work->name) === '' || $work->quantity <= 0) {
                Input::fail('Заполните названия и положительное количество работ');
            }

            $this->validateWorkPricing($work);
        }

        foreach ($req->parts()->get() as $part) {
            if (trim((string) $part->name) === '' || $part->quantity <= 0) {
                Input::fail('Укажите название и количество каждой запчасти');
            }
        }
    }

    private function validateManagerReviewStage(RepairRequest $req): void
    {
        $this->assertHasWorks($req);

        foreach ($req->workLines()->get() as $work) {
            if (trim((string) $work->name) === '' || $work->quantity <= 0) {
                Input::fail('Заполните название и положительное количество работы');
            }

            $this->validateWorkPricing($work);
        }
    }

    /**
     * ЗР без единой строки работ нельзя перевести далее по маршруту
     * (УК и Заказчик могут удалить работы или перенести их в Рекомендации).
     */
    public function assertHasWorks(RepairRequest $req): void
    {
        if (!$req->workLines()->exists()) {
            Input::fail(self::NO_WORKS_MESSAGE);
        }
    }

    public function validateWorkPricing(RequestWork $work): void
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

    private function validatePartsReceivedStage(RepairRequest $req): void
    {
        $approved = $req->approvals()
            ->where('stage', RepairStage::CustomerApproval->value)
            ->where('decision', ApprovalDecision::Accepted->value)
            ->where('estimate_version', $req->estimate_version)
            ->exists();

        if (!$approved) {
            Input::fail('Текущая смета должна быть согласована Заказчиком');
        }

        foreach ($req->parts()->get() as $part) {
            $needsReceive = $part->quantity > $part->returned_quantity;

            if ($needsReceive && $part->approved_version !== $req->estimate_version) {
                Input::fail('Запчасти текущей сметы должны быть согласованы');
            }
        }

        // Складской учёт: ЗЧ ЗР списываются со склада СТО на этапе «ТС готово
        // к выдаче Заказчику», поэтому их должно хватать на складе.
        app(StockService::class)->assertRequestCovered($req);
    }

    /* ─────────────────── Заказ-наряд ─────────────────── */

    private function completeWorkOrder(RepairRequest $req, string $conclusion): void
    {
        $req->repair_completed_at = now();
        $order = $req->workOrder;

        if ($order !== null) {
            $this->snapshotWorkOrder($order);
            WorkOrderItem::query()->where('work_order_id', $order->id)->delete();
        } else {
            $order = new WorkOrder(['repair_request_id' => $req->id]);
        }

        $order->fill([
            'vehicle_id'       => $req->vehicle_id,
            'station_id'       => $req->assigned_station_id,
            'mileage'          => $req->acceptanceAct?->mileage ?? (int) $req->data['mileage'],
            'completed_at'     => $req->repair_completed_at,
            'total'            => $this->repairRequestCalculator->total($req),
            'conclusion'       => $conclusion,
            'estimate_version' => $req->estimate_version,
        ]);

        $order->save();

        $this->createWorkOrderItemsFromWorks($req, $order);
        $this->createWorkOrderItemsFromParts($req, $order);

        DB::afterCommit(function () use ($req, $order): void {
            WorkOrderCompleted::dispatch($req, $order);
        });
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

    private function createWorkOrderItemsFromWorks(RepairRequest $req, WorkOrder $order): void
    {
        foreach ($req->workLines()->get() as $work) {
            $item = WorkOrderItem::query()->create([
                'work_order_id'  => $order->id,
                'kind'           => WorkOrderItemKind::Work,
                'name'           => $work->name,
                'quantity'       => $work->quantity,
                'price'          => $work->price,
                'work_type_id'   => $work->work_type_id,
                'norm_hours'     => $work->norm_hours,
                'actual_hours'   => $work->actual_hours,
                'is_maintenance' => $work->is_maintenance || (bool) $req->reasonRecord?->is_maintenance,
            ]);

            VehicleServiceRecord::query()->create([
                'vehicle_id'          => $req->vehicle_id,
                'repair_request_id'   => $req->id,
                'work_order_item_id'  => $item->id,
                'mileage'             => $order->mileage,
                'performed_at'        => $order->completed_at,
                'is_maintenance'      => $item->is_maintenance,
            ]);
        }
    }

    private function createWorkOrderItemsFromParts(RepairRequest $req, WorkOrder $order): void
    {
        foreach ($req->parts()->get() as $part) {
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
