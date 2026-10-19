<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Enums\PersonKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\RepairRequest;
use App\Models\RequestNotification;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleChangeRequest;
use App\Models\VehicleContact;
use App\Models\VehicleDriveType;
use App\Models\VehicleEngineVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Models\VehicleTransmissionType;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;

final class VehicleCatalogService
{
    private const CUSTOMER_DIRECT_FIELDS = [
        'baselineServiceMileage',
        'contactPersonIds',
        'driverPersonIds',
    ];

    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly CatalogAuditService $audit,
        private readonly DocumentLockService $documentLocks,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload): array
    {
        $this->assertEditor($user);
        $payload = $this->validatedPayload($user, $payload, true);

        if ($user->role === UserRole::Customer) {
            return $this->propose($user, null, 'create', $payload);
        }

        return DB::transaction(function () use ($user, $payload): array {
            $organization = $this->organizationForPayload($user, $payload);
            $vehicle = $this->applyCreate($user, $organization, $payload);

            return [
                'record'          => $this->vehicleService->present($vehicle, $user),
                'pendingApproval' => false,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function update(User $user, string $id, array $payload): array
    {
        $this->assertEditor($user);
        $vehicle = $this->resolveVehicle($user, $id);
        $this->documentLocks->assertAvailable(
            $user,
            'catalog_record',
            CatalogKind::Vehicles->value . ':' . $vehicle->uuid,
        );
        $payload = $this->validatedPayload($user, $payload, false);

        if ($user->role === UserRole::Customer) {
            return $this->updateAsCustomer($user, $vehicle, $payload);
        }

        return DB::transaction(function () use ($user, $vehicle, $payload): array {
            $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $vehicle = $this->applyUpdate($user, $vehicle, $payload);

            return [
                'record'          => $this->vehicleService->present($vehicle, $user),
                'pendingApproval' => false,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $user, string $id): array
    {
        $this->assertEditor($user);
        $vehicle = $this->resolveVehicle($user, $id);
        $this->documentLocks->assertAvailable(
            $user,
            'catalog_record',
            CatalogKind::Vehicles->value . ':' . $vehicle->uuid,
        );

        if ($user->role === UserRole::Customer) {
            return $this->propose($user, $vehicle, 'delete', []);
        }

        return DB::transaction(function () use ($user, $vehicle): array {
            $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $this->applyDelete($user, $vehicle);

            return [
                'record'          => null,
                'pendingApproval' => false,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function review(
        User $user,
        string $id,
        string $decision,
        string $comment,
    ): array {
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(403, 'FORBIDDEN', 'Согласование доступно только УК');
        }

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            Input::fail('Выберите решение approved или rejected', 'decision');
        }

        $this->documentLocks->assertAvailable(
            $user,
            'catalog_record',
            'vehicleApprovals:' . $id,
        );

        return DB::transaction(function () use ($user, $id, $decision, $comment): array {
            $change = VehicleChangeRequest::query()
                ->where('tenant_id', $user->tenantId())
                ->where('uuid', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($change->status !== 'pending') {
                Input::fail('Изменение уже рассмотрено');
            }

            $vehicle = $change->vehicle;

            if ($decision === 'approved') {
                $organization = $change->organization;

                $vehicle = match ($change->action) {
                    'create' => $this->applyCreate($user, $organization, $change->payload),
                    'update' => $this->applyUpdate(
                        $user,
                        Vehicle::query()->whereKey($vehicle?->id)->lockForUpdate()->firstOrFail(),
                        $change->payload,
                    ),
                    'delete' => $this->approveDelete($user, $vehicle),
                    default  => throw new ApiException(
                        422,
                        'VALIDATION_ERROR',
                        'Неизвестное действие',
                    ),
                };

                $change->vehicle_id = $vehicle?->id;
            }

            $before = $change->attributesToArray();
            $change->status = $decision;
            $change->reviewed_by = $user->id;
            $change->review_comment = trim($comment);
            $change->reviewed_at = now();
            $change->save();

            $this->audit->record(
                $user->tenantId(),
                $user,
                'vehicleApprovals',
                $change,
                $decision,
                $before,
                $change->attributesToArray(),
            );

            $this->deleteManagerNotifications($change);
            $this->notifyCustomer($change, $decision);

            return $this->presentChange($change->refresh());
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pending(User $user): array
    {
        if ($user->role !== UserRole::Manager) {
            return [];
        }

        return VehicleChangeRequest::query()
            ->with(['organization', 'vehicle', 'requester'])
            ->where('tenant_id', $user->tenantId())
            ->where('status', 'pending')
            ->oldest('created_at')
            ->get()
            ->map(fn (VehicleChangeRequest $change): array => $this->presentChange($change))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingForCustomer(User $user): array
    {
        if ($user->role !== UserRole::Customer) {
            return [];
        }

        return VehicleChangeRequest::query()
            ->with(['organization', 'vehicle', 'requester'])
            ->where('tenant_id', $user->tenantId())
            ->where('organization_id', $user->organization_id)
            ->where('status', 'pending')
            ->oldest('created_at')
            ->get()
            ->map(fn (VehicleChangeRequest $change): array => $this->presentChange($change))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentChange(VehicleChangeRequest $change): array
    {
        $change->loadMissing(['organization', 'vehicle', 'requester', 'reviewer']);
        $rawPayload = $this->canonicalizeVehicleReference(
            $this->normalizeVehiclePayload($change->payload),
        );
        $payload = $this->presentPayloadPeople(
            $rawPayload,
            $change->organization_id,
        );

        return [
            'id'           => $change->uuid,
            'uuid'         => $change->uuid,
            'systemId'     => $change->id,
            'kind'         => 'vehicleApprovals',
            'action'       => $change->action,
            'status'       => $change->status,
            'vehicleId'    => $change->vehicle?->uuid ?? '',
            'vehicle'      => $payload['vehicle'] ?? $change->vehicle?->name ?? '',
            'vin'          => $payload['vin'] ?? $change->vehicle?->vin ?? '',
            'plate'        => $payload['plate'] ?? $change->vehicle?->plate ?? '',
            'company'      => $change->organization->name,
            'organizationId' => $change->organization->uuid,
            'requestedBy'  => $change->requester->display_name,
            'reviewedBy'   => $change->reviewer?->display_name ?? '',
            'reviewComment' => $change->review_comment,
            'payload'      => $payload,
            'changes'      => $this->changeDetails($change, $rawPayload, $payload),
            'createdAt'    => $change->created_at->toISOString(),
            'reviewedAt'   => $change->reviewed_at?->toISOString(),
            'permissions'  => [
                'canEdit'   => false,
                'canReview' => $change->status === 'pending',
            ],
        ];
    }

    private function assertEditor(User $user): void
    {
        if (!in_array($user->role, [UserRole::Customer, UserRole::Manager], true)) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Справочник ТС Заказчиков недоступен',
            );
        }
    }

    private function resolveVehicle(User $user, string $id): Vehicle
    {
        return Vehicle::forUser($user)
            ->where('uuid', $id)
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validatedPayload(User $user, array $payload, bool $create): array
    {
        $payload = $this->normalizeVehiclePayload($payload);

        if ($user->role === UserRole::Customer) {
            unset(
                $payload['organizationId'],
                $payload['laborHourRate'],
                $payload['laborHourRateOfficial'],
                $payload['laborHourRateOther'],
                $payload['laborRateCoefficient'],
                $payload['baselineServiceAt'],
            );
        }

        $payload = $this->normalizePeoplePayload($payload, $create);

        $required = $create ? 'required' : 'sometimes';

        $laborHourFormat = 'Стоимость нормо/часа введена неверно. Правильно: '
            . 'число от 0 до 1000000000, не более двух знаков после запятой, '
            . 'например 2500,00.';
        $coefficientFormat = 'Поправочный коэффициент введён неверно. Правильно: '
            . 'число от 0,001 до 100, не более трёх знаков после запятой, '
            . 'например 1,000.';

        $validated = Input::validate($payload, [
            'make'                     => [$required, 'required', 'string', 'max:255'],
            'model'                    => [$required, 'required', 'string', 'max:255'],
            'vehicleModelId'           => [$create ? 'required' : 'sometimes', 'uuid'],
            'vin'                      => [$required, 'required', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
            'plate'                    => ['sometimes', 'string', 'max:30', 'regex:/^[\p{L}\p{N} -]{0,30}$/u'],
            'year'                     => ['nullable', 'integer', 'between:1900,2100'],
            'baselineServiceMileage'   => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'baselineServiceAt'        => ['nullable', 'date'],
            'organizationId'           => $user->role === UserRole::Manager
                ? [$create ? 'required' : 'sometimes', 'uuid']
                : ['sometimes'],
            'laborHourRateOfficial' => ['nullable', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
            'laborHourRateOther' => ['nullable', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
            'laborHourRate'            => [
                'nullable',
                'numeric',
                'min:0',
                'max:1000000000',
                Input::numeric(2, $laborHourFormat),
            ],
            'laborRateCoefficient'     => [
                'sometimes',
                'numeric',
                'min:0.001',
                'max:100',
                Input::numeric(3, $coefficientFormat),
            ],
            'contactPersonIds'         => ['array', 'list', 'max:100'],
            'contactPersonIds.*'       => ['required', 'uuid', 'distinct'],
            'driverPersonIds'          => ['array', 'list', 'max:100'],
            'driverPersonIds.*'        => ['required', 'uuid', 'distinct'],
        ], [
            'make.required' => 'Марка не выбрана. Правильно: выберите марку '
                . 'из выпадающего списка либо создайте новую марку и модель.',
            'make.string' => 'Марка введена неверно. Правильно: выберите марку '
                . 'из выпадающего списка либо создайте новую марку и модель.',
            'make.max' => 'Марка введена неверно. Правильно: не более 255 символов.',
            'model.required' => 'Модель не выбрана. Правильно: сначала выберите '
                . 'марку, затем модель из выпадающего списка.',
            'model.string' => 'Модель введена неверно. Правильно: сначала выберите '
                . 'марку, затем модель из выпадающего списка.',
            'model.max' => 'Модель введена неверно. Правильно: не более 255 символов.',
            'vehicleModelId.uuid' => 'Марка или модель выбрана неверно. Правильно: '
                . 'выберите значение из выпадающего списка.',
            'vin.required' => 'VIN не заполнен. Правильно: укажите ровно 17 '
                . 'латинских букв и цифр без I, O и Q.',
            'vin.string' => 'VIN введён неверно. Правильно: ровно 17 латинских '
                . 'букв и цифр без I, O и Q, например X96330200R0000001.',
            'vin.regex' => 'VIN введён неверно. Правильно: ровно 17 латинских '
                . 'букв и цифр без I, O и Q, например X96330200R0000001.',
            'plate.string' => 'Госномер введён неверно. Правильно: буквы и цифры; '
                . 'допустимы пробел и дефис, например А111АА78.',
            'plate.max' => 'Госномер введён неверно. Правильно: не более 30 символов.',
            'plate.regex' => 'Госномер введён неверно. Правильно: буквы и цифры; '
                . 'допустимы пробел и дефис, например А111АА78.',
            'year.integer' => 'Год выпуска введён неверно. Правильно: целое число '
                . 'от 1900 до 2100, например 2025.',
            'year.between' => 'Год выпуска введён неверно. Правильно: целое число '
                . 'от 1900 до 2100, например 2025.',
            'baselineServiceMileage.integer' => 'Предыдущий пробег введён неверно. '
                . 'Правильно: целое число от 0 до 999999999, например 200.',
            'baselineServiceMileage.min' => 'Предыдущий пробег введён неверно. '
                . 'Правильно: целое число от 0 до 999999999, например 200.',
            'baselineServiceMileage.max' => 'Предыдущий пробег введён неверно. '
                . 'Правильно: целое число от 0 до 999999999, например 200.',
            'organizationId.required' => 'Организация не выбрана. Правильно: '
                . 'выберите заказчика из выпадающего списка.',
            'organizationId.uuid' => 'Организация выбрана неверно. Правильно: '
                . 'выберите заказчика из выпадающего списка.',
            'laborHourRate.numeric' => $laborHourFormat,
            'laborHourRate.min' => $laborHourFormat,
            'laborHourRate.max' => $laborHourFormat,
            'laborRateCoefficient.numeric' => $coefficientFormat,
            'laborRateCoefficient.min' => $coefficientFormat,
            'laborRateCoefficient.max' => $coefficientFormat,
            'contactPersonIds.array' => 'Контактные лица выбраны неверно. Правильно: '
                . 'выберите записи из справочника этой организации.',
            'contactPersonIds.list' => 'Контактные лица выбраны неверно. Правильно: '
                . 'выберите записи из справочника этой организации.',
            'contactPersonIds.max' => 'Выбрано слишком много контактных лиц. '
                . 'Правильно: не более 100 записей из справочника организации.',
            'contactPersonIds.*.required' => 'Контактное лицо не выбрано. Правильно: '
                . 'выберите запись из справочника этой организации.',
            'contactPersonIds.*.uuid' => 'Контактное лицо выбрано неверно. Правильно: '
                . 'выберите запись из справочника этой организации.',
            'contactPersonIds.*.distinct' => 'Контактное лицо повторяется. Правильно: '
                . 'выберите каждое лицо только один раз.',
            'driverPersonIds.array' => 'Водители выбраны неверно. Правильно: '
                . 'выберите записи из справочника этой организации.',
            'driverPersonIds.list' => 'Водители выбраны неверно. Правильно: '
                . 'выберите записи из справочника этой организации.',
            'driverPersonIds.max' => 'Выбрано слишком много водителей. Правильно: '
                . 'не более 100 записей из справочника организации.',
            'driverPersonIds.*.required' => 'Водитель не выбран. Правильно: '
                . 'выберите запись из справочника этой организации.',
            'driverPersonIds.*.uuid' => 'Водитель выбран неверно. Правильно: '
                . 'выберите запись из справочника этой организации.',
            'driverPersonIds.*.distinct' => 'Водитель повторяется. Правильно: '
                . 'выберите каждого водителя только один раз.',
        ]);

        return $this->canonicalizeVehicleReference($validated);
    }

    /**
     * Пробег и связи с людьми Заказчик меняет сразу.
     * Остальные изменения остаются заявкой на согласование УК.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function updateAsCustomer(User $user, Vehicle $vehicle, array $payload): array
    {
        return DB::transaction(function () use ($user, $vehicle, $payload): array {
            $vehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();
            $current = $this->currentPayload($vehicle);
            $changed = [];

            foreach ($payload as $field => $value) {
                if (!$this->sameValue($current[$field] ?? null, $value)) {
                    $changed[$field] = $value;
                }
            }

            $direct = array_intersect_key(
                $changed,
                array_flip(self::CUSTOMER_DIRECT_FIELDS),
            );
            $approval = array_diff_key(
                $changed,
                array_flip(self::CUSTOMER_DIRECT_FIELDS),
            );

            if (array_key_exists('baselineServiceMileage', $direct)) {
                $direct['baselineServiceAt'] = $direct['baselineServiceMileage'] === null
                    ? null
                    : now();
            }

            if ($direct !== []) {
                $vehicle = $this->applyUpdate($user, $vehicle, $direct);
            }

            if ($approval !== []) {
                return $this->propose($user, $vehicle, 'update', $approval);
            }

            return [
                'record'          => $this->vehicleService->present($vehicle, $user),
                'pendingApproval' => false,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function currentPayload(Vehicle $vehicle): array
    {
        $vehicle->load(['contacts.organizationPerson', 'modelReference']);

        $personIds = static function (Vehicle $vehicle, PersonKind $kind): array {
            return $vehicle->contacts
                ->where('kind', $kind)
                ->where('active', true)
                ->map(fn (VehicleContact $contact): ?string => $contact->organizationPerson?->uuid)
                ->filter()
                ->values()
                ->all();
        };

        return [
            'make'                   => $vehicle->make,
            'model'                  => $vehicle->model,
            'vehicleModelId'         => $vehicle->modelReference?->uuid,
            'vin'                    => $vehicle->vin,
            'plate'                  => $vehicle->plate,
            'year'                   => $vehicle->year,
            'baselineServiceMileage' => $vehicle->baseline_service_mileage,
            'contactPersonIds'       => $personIds($vehicle, PersonKind::Contact),
            'driverPersonIds'        => $personIds($vehicle, PersonKind::Driver),
        ];
    }

    private function sameValue(mixed $current, mixed $incoming): bool
    {
        if (is_array($current) && is_array($incoming)) {
            sort($current);
            sort($incoming);

            return $current === $incoming;
        }

        if ($current === null || $incoming === null) {
            return $current === $incoming;
        }

        return (string) $current === (string) $incoming;
    }

    /**
     * Принимает прежнее поле `vehicle`, чтобы незавершённые согласования и
     * старые клиенты продолжили работать после разделения марки и модели.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeVehiclePayload(array $payload): array
    {
        if (array_key_exists('vehicle', $payload)) {
            $legacyName = trim((string) $payload['vehicle']);

            if (!array_key_exists('make', $payload) || !array_key_exists('model', $payload)) {
                [$make, $model] = array_pad(explode(' ', $legacyName, 2), 2, '');
                $payload['make'] = $payload['make'] ?? $make;
                $payload['model'] = $payload['model'] ?? $model;
            }

            unset($payload['vehicle']);
        }

        if (($payload['vehicleModelId'] ?? null) === '') {
            $payload['vehicleModelId'] = null;
        }

        return $payload;
    }

    /**
     * Если выбран вариант справочника, канонические марка и модель берутся из
     * него. Для прежних записей пытаемся найти вариант по текстовым значениям.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function canonicalizeVehicleReference(array $payload): array
    {
        $reference = null;
        $referenceWasProvided = array_key_exists('vehicleModelId', $payload);

        if (!empty($payload['vehicleModelId'])) {
            $reference = VehicleModel::query()
                ->with('make')
                ->where('uuid', $payload['vehicleModelId'])
                ->where('active', true)
                ->first();

            if ($reference === null) {
                Input::fail('Выберите модель из внутреннего справочника', 'vehicleModelId');
            }
        } elseif (
            !$referenceWasProvided
            && isset($payload['make'], $payload['model'])
        ) {
            $make = VehicleReferenceService::normalizeName((string) $payload['make']);
            $model = VehicleReferenceService::normalizeName((string) $payload['model']);

            $reference = VehicleModel::query()
                ->with('make')
                ->where('normalized_name', $model)
                ->whereHas(
                    'make',
                    fn ($query) => $query->where('normalized_name', $make),
                )
                ->oldest('id')
                ->first();
        }

        if ($reference !== null) {
            $payload['vehicleModelId'] = $reference->uuid;
            $payload['make'] = $reference->make->name;
            $payload['model'] = $reference->name;
            if (isset($payload['year']) && (
                ($reference->model_year_from !== null && $payload['year'] < $reference->model_year_from)
                || ($reference->model_year_to !== null && $payload['year'] > $reference->model_year_to)
            )) {
                Input::fail('Год выпуска не входит в диапазон выбранного поколения / варианта', 'year');
            }
        } else {
            if (array_key_exists('make', $payload)) {
                $payload['make'] = VehicleReferenceService::cleanName(
                    (string) $payload['make'],
                );
            }

            if (array_key_exists('model', $payload)) {
                $payload['model'] = VehicleReferenceService::cleanName(
                    (string) $payload['model'],
                );
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function vehicleModelId(array $payload): ?int
    {
        if (empty($payload['vehicleModelId'])) {
            return null;
        }

        return VehicleModel::query()
            ->where('uuid', $payload['vehicleModelId'])
            ->value('id');
    }

    /**
     * @return array<string, int|null>
     */
    private function typedApplicability(?int $vehicleModelId): array
    {
        $empty = [
            'vehicle_generation_id' => null,
            'vehicle_engine_variant_id' => null,
            'vehicle_transmission_type_id' => null,
            'vehicle_drive_type_id' => null,
        ];

        if ($vehicleModelId === null) {
            return $empty;
        }

        $model = VehicleModel::query()->find($vehicleModelId);
        if ($model === null) {
            return $empty;
        }

        $generationId = VehicleGeneration::query()
            ->where('vehicle_model_id', $model->id)
            ->where('active', true)
            ->value('id');
        $engineId = VehicleEngineVariant::query()
            ->where('vehicle_model_id', $model->id)
            ->where('fingerprint', $model->fingerprint)
            ->where('active', true)
            ->value('id');
        $transmissionId = $model->transmission === null
            ? null
            : VehicleTransmissionType::query()
                ->whereRaw('lower(name) = lower(?)', [$model->transmission])
                ->where('active', true)
                ->value('id');
        $driveId = $model->drive_type === null
            ? null
            : VehicleDriveType::query()
                ->whereRaw('lower(name) = lower(?)', [$model->drive_type])
                ->where('active', true)
                ->value('id');

        return [
            'vehicle_generation_id' => $generationId,
            'vehicle_engine_variant_id' => $engineId,
            'vehicle_transmission_type_id' => $transmissionId,
            'vehicle_drive_type_id' => $driveId,
        ];
    }

    /**
     * Поддерживает прежний формат contacts/drivers, но сохраняет только ссылки
     * на отдельные справочники организации.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizePeoplePayload(array $payload, bool $create): array
    {
        foreach ([
            'contacts' => [
                'target' => 'contactPersonIds',
                'kind' => PersonKind::Contact,
            ],
            'drivers' => [
                'target' => 'driverPersonIds',
                'kind' => PersonKind::Driver,
            ],
        ] as $legacy => $settings) {
            $target = $settings['target'];

            if (!array_key_exists($target, $payload)) {
                if (array_key_exists($legacy, $payload) && is_array($payload[$legacy])) {
                    $payload[$target] = array_map(
                        function (mixed $person) use ($settings): string {
                            if (is_string($person)) {
                                return $person;
                            }

                            if (!is_array($person)) {
                                Input::fail('Выберите запись из справочника организации');
                            }

                            if (!empty($person['personId'])) {
                                return (string) $person['personId'];
                            }

                            $assignment = VehicleContact::query()
                                ->with('organizationPerson')
                                ->where('kind', $settings['kind']->value)
                                ->where('uuid', $person['id'] ?? '')
                                ->first();

                            if ($assignment?->organizationPerson === null) {
                                Input::fail(
                                    'Контактные лица и водители выбираются из справочника',
                                );
                            }

                            return $assignment->organizationPerson->uuid;
                        },
                        $payload[$legacy],
                    );
                } elseif ($create) {
                    $payload[$target] = [];
                }
            }

            unset($payload[$legacy]);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function organizationForPayload(User $user, array $payload): Organization
    {
        return Organization::query()
            ->where('tenant_id', $user->tenantId())
            ->where('kind', OrganizationKind::Customer->value)
            ->where('uuid', $payload['organizationId'] ?? '')
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function propose(User $user, ?Vehicle $vehicle, string $action, array $payload): array
    {
        return DB::transaction(function () use ($user, $vehicle, $action, $payload): array {
            if ($vehicle !== null) {
                $pending = VehicleChangeRequest::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->where('status', 'pending')
                    ->exists();

                if ($pending) {
                    Input::fail(
                        'По автомобилю уже есть изменение на согласовании УК',
                    );
                }
            }

            $change = VehicleChangeRequest::query()->create([
                'tenant_id'      => $user->tenantId(),
                'organization_id' => $user->organization_id,
                'vehicle_id'     => $vehicle?->id,
                'requested_by'   => $user->id,
                'action'         => $action,
                'status'         => 'pending',
                'payload'        => $payload,
            ]);

            $this->audit->record(
                $user->tenantId(),
                $user,
                'vehicleApprovals',
                $change,
                'requested_' . $action,
                null,
                $change->attributesToArray(),
            );

            $this->notifyManagers($change);

            $presentedPayload = $this->presentPayloadPeople(
                $payload,
                $user->organization_id,
            );
            $record = $vehicle !== null
                ? $this->vehicleService->present($vehicle, $user)
                : array_merge($presentedPayload, [
                    'id'          => $change->uuid,
                    'kind'        => CatalogKind::Vehicles->value,
                    'company'     => $user->organization->name,
                    'contactNames' => collect($presentedPayload['contacts'] ?? [])
                        ->pluck('fullName')
                        ->implode(', '),
                    'driverNames' => collect($presentedPayload['drivers'] ?? [])
                        ->pluck('fullName')
                        ->implode(', '),
                    'permissions' => ['canEdit' => false],
                ]);

            $record['pendingApproval'] = true;
            $record['changeRequestId'] = $change->uuid;

            return [
                'record'          => $record,
                'changeRequest'   => $this->presentChange($change),
                'pendingApproval' => true,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCreate(User $actor, Organization $organization, array $payload): Vehicle
    {
        $payload = $this->canonicalizeVehicleReference(
            $this->normalizeVehiclePayload($payload),
        );
        $this->assertUniqueVin((string) $payload['vin']);

        $make = trim((string) $payload['make']);
        $model = trim((string) $payload['model']);
        $vehicleModelId = $this->vehicleModelId($payload);

        $vehicle = Vehicle::query()->create(array_merge([
            'tenant_id'                 => $actor->tenantId(),
            'organization_id'           => $organization->id,
            'name'                      => trim($make . ' ' . $model),
            'make'                      => $make,
            'model'                     => $model,
            'vehicle_model_id'          => $vehicleModelId,
            'vin'                       => strtoupper((string) $payload['vin']),
            'plate'                     => mb_strtoupper((string) ($payload['plate'] ?? '')),
            'plate_normalized'          => Vehicle::plateKey((string) ($payload['plate'] ?? '')),
            'year'                      => $payload['year'] ?? null,
            'baseline_service_mileage'  => $payload['baselineServiceMileage'] ?? null,
            'baseline_service_at'       => $payload['baselineServiceAt'] ?? null,
            'labor_rate_coefficient'    => $payload['laborRateCoefficient'] ?? 1,
            'labor_hour_rate'           => $payload['laborHourRate'] ?? null,
            'labor_hour_rate_official' => $payload['laborHourRateOfficial'] ?? null,
            'labor_hour_rate_other' => $payload['laborHourRateOther'] ?? null,
            'active'                    => true,
        ], $this->typedApplicability($vehicleModelId)));

        $this->syncPeople($vehicle, PersonKind::Contact, $payload['contactPersonIds'] ?? []);
        $this->syncPeople($vehicle, PersonKind::Driver, $payload['driverPersonIds'] ?? []);

        $this->audit->record(
            $actor->tenantId(),
            $actor,
            CatalogKind::Vehicles,
            $vehicle,
            'create',
            null,
            $this->auditPayload($vehicle),
        );

        return $vehicle->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyUpdate(User $actor, Vehicle $vehicle, array $payload): Vehicle
    {
        $payload = $this->canonicalizeVehicleReference(
            $this->normalizeVehiclePayload($payload),
        );
        $before = $this->auditPayload($vehicle);

        if (isset($payload['organizationId'])) {
            $organization = $this->organizationForPayload($actor, $payload);

            if ($organization->id !== $vehicle->organization_id) {
                $used = RepairRequest::query()->where('vehicle_id', $vehicle->id)->exists();

                if ($used) {
                    Input::fail(
                        'Нельзя изменить владельца ТС, которое используется в заявках',
                    );
                }

                $vehicle->organization_id = $organization->id;
            }
        }

        if (isset($payload['vin']) && $payload['vin'] !== $vehicle->vin) {
            $this->assertUniqueVin((string) $payload['vin'], $vehicle->id);
        }

        $map = [
            'make'                    => 'make',
            'model'                   => 'model',
            'vin'                     => 'vin',
            'plate'                   => 'plate',
            'year'                    => 'year',
            'baselineServiceMileage'  => 'baseline_service_mileage',
            'baselineServiceAt'       => 'baseline_service_at',
            'laborRateCoefficient'    => 'labor_rate_coefficient',
            'laborHourRate'           => 'labor_hour_rate',
            'laborHourRateOfficial' => 'labor_hour_rate_official',
            'laborHourRateOther' => 'labor_hour_rate_other',
        ];

        foreach ($map as $key => $attribute) {
            if (array_key_exists($key, $payload)) {
                $vehicle->{$attribute} = $payload[$key];
            }
        }

        if (array_key_exists('vehicleModelId', $payload)) {
            $vehicleModelId = $this->vehicleModelId($payload);
            $vehicle->vehicle_model_id = $vehicleModelId;
            $vehicle->forceFill($this->typedApplicability($vehicleModelId));
        }

        $vehicle->name = trim($vehicle->make . ' ' . $vehicle->model);
        $vehicle->vin = strtoupper((string) $vehicle->vin);
        $vehicle->plate = mb_strtoupper((string) $vehicle->plate);
        $vehicle->plate_normalized = Vehicle::plateKey($vehicle->plate);
        $vehicle->save();

        if (array_key_exists('contactPersonIds', $payload)) {
            $this->syncPeople($vehicle, PersonKind::Contact, $payload['contactPersonIds']);
        }

        if (array_key_exists('driverPersonIds', $payload)) {
            $this->syncPeople($vehicle, PersonKind::Driver, $payload['driverPersonIds']);
        }

        $this->audit->record(
            $actor->tenantId(),
            $actor,
            CatalogKind::Vehicles,
            $vehicle,
            'update',
            $before,
            $this->auditPayload($vehicle),
        );

        return $vehicle->refresh();
    }

    private function applyDelete(User $actor, Vehicle $vehicle): void
    {
        $before = $this->auditPayload($vehicle);
        $vehicle->active = false;
        $vehicle->save();

        $this->audit->record(
            $actor->tenantId(),
            $actor,
            CatalogKind::Vehicles,
            $vehicle,
            'delete',
            $before,
            $this->auditPayload($vehicle),
        );
    }

    private function approveDelete(User $actor, ?Vehicle $vehicle): ?Vehicle
    {
        if ($vehicle === null) {
            Input::fail('Автомобиль для удаления не найден');
        }

        $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
        $this->applyDelete($actor, $vehicle);

        return $vehicle;
    }

    private function assertUniqueVin(string $vin, ?int $exceptId = null): void
    {
        $exists = Vehicle::query()
            ->where('vin', strtoupper($vin))
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            Input::fail(
                'VIN уже используется другим автомобилем. Правильно: укажите '
                    . 'уникальный 17-значный VIN этого ТС.',
                'vin',
            );
        }
    }

    /**
     * @param array<int, string> $personIds
     */
    private function syncPeople(
        Vehicle $vehicle,
        PersonKind $kind,
        array $personIds,
    ): void {
        $people = OrganizationPerson::query()
            ->where('organization_id', $vehicle->organization_id)
            ->where('kind', $kind->value)
            ->where('active', true)
            ->whereIn('uuid', $personIds)
            ->get()
            ->keyBy('uuid');

        if ($people->count() !== count(array_unique($personIds))) {
            Input::fail(
                $kind === PersonKind::Driver
                    ? 'Выберите водителей из справочника организации'
                    : 'Выберите контактных лиц из справочника организации',
            );
        }

        $keptIds = [];

        foreach ($personIds as $personId) {
            $person = $people->get($personId);
            $phoneConflict = VehicleContact::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('phone', $person->phone)
                ->where('organization_person_id', '!=', $person->id)
                ->exists();

            if ($phoneConflict) {
                Input::fail(
                    'Одно лицо не может быть добавлено к автомобилю повторно',
                );
            }

            $record = VehicleContact::query()->firstOrNew([
                'vehicle_id'            => $vehicle->id,
                'organization_person_id' => $person->id,
            ]);
            $record->fill([
                'kind'        => $kind,
                'last_name'   => $person->last_name,
                'first_name'  => $person->first_name,
                'middle_name' => $person->middle_name,
                'phone'       => $person->phone,
                'active'      => true,
            ]);
            $record->save();
            $keptIds[] = $record->id;
        }

        VehicleContact::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('kind', $kind->value)
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->update(['active' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Vehicle $vehicle): array
    {
        $vehicle->load('contacts.organizationPerson');

        return array_merge($vehicle->attributesToArray(), [
            'contacts' => $vehicle->contacts
                ->where('kind', PersonKind::Contact)
                ->where('active', true)
                ->map->snapshot()
                ->values()
                ->all(),
            'drivers' => $vehicle->contacts
                ->where('kind', PersonKind::Driver)
                ->where('active', true)
                ->map->snapshot()
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function presentPayloadPeople(array $payload, int $organizationId): array
    {
        foreach ([
            'contactPersonIds' => 'contacts',
            'driverPersonIds'  => 'drivers',
        ] as $idsField => $target) {
            $ids = $payload[$idsField] ?? [];
            $people = OrganizationPerson::query()
                ->where('organization_id', $organizationId)
                ->whereIn('uuid', is_array($ids) ? $ids : [])
                ->get()
                ->keyBy('uuid');

            $payload[$target] = collect(is_array($ids) ? $ids : [])
                ->map(function (string $id) use ($people): ?array {
                    $person = $people->get($id);

                    if ($person === null) {
                        return null;
                    }

                    return [
                        'personId'   => $person->uuid,
                        'lastName'   => $person->last_name,
                        'firstName'  => $person->first_name,
                        'middleName' => $person->middle_name ?? '',
                        'fullName'   => $person->fullName(),
                        'phone'      => \App\Support\RussianPhone::format($person->phone),
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        if (array_key_exists('make', $payload) || array_key_exists('model', $payload)) {
            $payload['vehicle'] = trim(
                (string) ($payload['make'] ?? '')
                . ' '
                . (string) ($payload['model'] ?? ''),
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed> $presentedPayload
     *
     * @return array<int, array{field: string, label: string, before: string, after: string}>
     */
    private function changeDetails(
        VehicleChangeRequest $change,
        array $rawPayload,
        array $presentedPayload,
    ): array {
        if ($change->action === 'delete') {
            return [[
                'field'  => 'record',
                'label'  => 'Запись ТС',
                'before' => $change->vehicle?->name ?? '',
                'after'  => 'Удаление записи',
            ]];
        }

        $labels = [
            'make'                   => 'Марка',
            'model'                  => 'Модель',
            'vehicleModelId'         => 'Запись справочника моделей',
            'vin'                    => 'VIN',
            'plate'                  => 'Госномер',
            'year'                   => 'Год выпуска',
            'baselineServiceMileage' => 'Предыдущий пробег',
            'baselineServiceAt'      => 'Дата предыдущего пробега',
            'contactPersonIds'       => 'Контактные лица',
            'driverPersonIds'        => 'Водители',
        ];
        $current = $this->currentVehiclePayload($change->vehicle);
        $rows = [];

        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $rawPayload)) {
                continue;
            }

            $before = $change->action === 'create'
                ? null
                : ($current[$field] ?? null);
            $after = $rawPayload[$field];

            if ($change->action !== 'create' && $this->sameValue($before, $after)) {
                continue;
            }

            $rows[] = [
                'field'  => $field,
                'label'  => $label,
                'before' => $this->changeValue($field, $before, $current),
                'after'  => $this->changeValue($field, $after, $presentedPayload),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentVehiclePayload(?Vehicle $vehicle): array
    {
        if ($vehicle === null) {
            return [];
        }

        $vehicle->loadMissing(['contacts.organizationPerson', 'modelReference']);

        return [
            'make'                   => $vehicle->make,
            'model'                  => $vehicle->model,
            'vehicleModelId'         => $vehicle->modelReference?->uuid,
            'vin'                    => $vehicle->vin,
            'plate'                  => $vehicle->plate,
            'year'                   => $vehicle->year,
            'baselineServiceMileage' => $vehicle->baseline_service_mileage,
            'baselineServiceAt'      => $vehicle->baseline_service_at?->toDateString(),
            'contactPersonIds'       => $vehicle->contacts
                ->where('kind', PersonKind::Contact)
                ->where('active', true)
                ->pluck('organizationPerson.uuid')
                ->filter()
                ->values()
                ->all(),
            'driverPersonIds'        => $vehicle->contacts
                ->where('kind', PersonKind::Driver)
                ->where('active', true)
                ->pluck('organizationPerson.uuid')
                ->filter()
                ->values()
                ->all(),
            'contacts'               => $vehicle->contacts
                ->where('kind', PersonKind::Contact)
                ->where('active', true)
                ->map->snapshot()
                ->values()
                ->all(),
            'drivers'                => $vehicle->contacts
                ->where('kind', PersonKind::Driver)
                ->where('active', true)
                ->map->snapshot()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function changeValue(string $field, mixed $value, array $source): string
    {
        if (in_array($field, ['contactPersonIds', 'driverPersonIds'], true)) {
            $key = $field === 'contactPersonIds' ? 'contacts' : 'drivers';

            return collect($source[$key] ?? [])
                ->map(static fn (array $person): string => $person['fullName'] ?? '')
                ->filter()
                ->implode(', ') ?: 'Не выбраны';
        }

        if ($value === null || $value === '') {
            return 'Не указано';
        }

        return (string) $value;
    }

    private function deleteManagerNotifications(VehicleChangeRequest $change): void
    {
        RequestNotification::query()
            ->where('vehicle_change_request_id', $change->id)
            ->where('kind', 'vehicle_catalog_approval')
            ->delete();
    }

    private function notifyManagers(VehicleChangeRequest $change): void
    {
        $managerIds = User::query()
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->where('role', UserRole::Manager->value)
            ->whereIn(
                'organization_id',
                Organization::query()
                    ->select('id')
                    ->where('tenant_id', $change->tenant_id)
                    ->where('kind', OrganizationKind::Manager->value),
            )
            ->pluck('id');

        foreach ($managerIds as $userId) {
            RequestNotification::query()->create([
                'user_id'                   => $userId,
                'repair_request_id'         => null,
                'vehicle_change_request_id' => $change->id,
                'request_event_id'          => null,
                'kind'                      => 'vehicle_catalog_approval',
                'title'                     => 'Согласование изменений ТС',
                'message'                   => $change->organization->name
                    . ': требуется проверить изменение '
                    . 'справочника автомобилей',
            ]);
        }
    }

    private function notifyCustomer(VehicleChangeRequest $change, string $decision): void
    {
        $userIds = User::query()
            ->where('organization_id', $change->organization_id)
            ->where('active', true)
            ->whereNull('access_revoked_at')
            ->pluck('id');

        foreach ($userIds as $userId) {
            RequestNotification::query()->create([
                'user_id'                   => $userId,
                'repair_request_id'         => null,
                'vehicle_change_request_id' => $change->id,
                'request_event_id'          => null,
                'kind'                      => 'vehicle_catalog_' . $decision,
                'title'                     => $decision === 'approved'
                    ? 'Изменение ТС согласовано'
                    : 'Изменение ТС отклонено',
                'message'                   => $change->review_comment !== ''
                    ? $change->review_comment
                    : 'Решение Управляющей компании сохранено',
            ]);
        }
    }
}
