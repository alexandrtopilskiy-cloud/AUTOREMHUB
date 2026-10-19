<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\CatalogPart;
use App\Models\MaintenanceRegulation;
use App\Models\MaintenanceRegulationItem;
use App\Models\Organization;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\TimeNorm;
use App\Models\TimeNormApplicability;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDriveType;
use App\Models\VehicleEngineVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleTransmissionType;
use App\Models\WorkType;
use App\Support\DomainInput as Input;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CatalogService
{
    private const ORGANIZATION_FIELDS = [
        'shortName'             => 'short_name',
        'inn'                   => 'inn',
        'kpp'                   => 'kpp',
        'ogrn'                  => 'ogrn',
        'legalAddress'          => 'legal_address',
        'actualAddress'         => 'actual_address',
        'postalAddress'         => 'postal_address',
        'directorName'          => 'director_name',
        'phone'                 => 'phone',
        'email'                 => 'email',
        'bankName'              => 'bank_name',
        'bik'                   => 'bik',
        'settlementAccount'     => 'settlement_account',
        'correspondentAccount'  => 'correspondent_account',
        'taxRegime'             => 'tax_regime',
        'vatRate'               => 'vat_rate',
    ];

    private const STATION_PROFILE_FIELDS = [
        'mechanicalBays',
        'lifts',
        'mechanicsCount',
        'electriciansCount',
        'electricianBays',
        'alignmentBays',
        'engineBays',
        'paintBooths',
        'frameRacks',
        'bodyworkBays',
        'engineRepair',
        'automaticTransmissionRepair',
        'manualTransmissionRepair',
        'steeringRackRepair',
        'bodyRepair',
        'brakeDiscSkimming',
        'wheelAlignment',
        'tireService',
        'tireStorage',
        'evacuation',
        'warrantyService',
        'reception',
        'waitingArea',
        'photoVideoFixation',
        'itAccounting',
        'insuranceFleetExperience',
        'trainingCertificates',
        'passengerCars',
        'lcv',
        'trucks',
        'slaDescription',
        'vehicleRestrictions',
        'customerAmenities',
        'uniqueServices',
        'yearsOperating',
    ];

    /** @var array<string, class-string<Model>> */
    public const MODELS = [
        'workTypes' => WorkType::class,
        'timeNorms' => TimeNorm::class,
        'normApplicabilities' => TimeNormApplicability::class,
        'parts' => CatalogPart::class,
        'partManufacturers' => \App\Models\PartManufacturer::class,
        'reasons' => ServiceReason::class,
        'regulations' => MaintenanceRegulation::class,
        'suppliers' => Supplier::class,
    ];

    /** @var array<int, float|string|null> */
    private array $laborHourRateSummaries = [];

    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly CatalogAuditService $audit,
        private readonly VehicleCatalogService $vehicleCatalogService,
        private readonly OrganizationPersonService $organizationPersonService,
        private readonly DocumentLockService $documentLocks,
        private readonly UserDirectoryService $userDirectoryService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function reference(User $user): array
    {
        $tenantId = $user->tenantId();
        $reasonQuery = ServiceReason::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true);

        if ($user->role === UserRole::Customer) {
            $rootIds = ServiceReason::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('code', ['maintenance', 'diagnostics'])
                ->pluck('id');

            $reasonQuery->where(function ($query) use ($rootIds): void {
                $query->whereIn('id', $rootIds)
                    ->orWhereIn('parent_id', $rootIds);
            });
        }

        // 1. Загружаем всё заранее
        $reasons = $reasonQuery->orderBy('id')->get();

        $workTypes = WorkType::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->get();

        $workTypeIds = $workTypes->pluck('id');

        // Нормы грузим ОДНИМ запросом с eager load
        $timeNorms = TimeNorm::query()
            ->whereIn('work_type_id', $workTypeIds)
            ->where('active', true)
            ->with($this->timeNormRelations())
            ->get();

        // Группируем нормы по work_type_id — O(1) доступ
        $normsByWorkType = $timeNorms->groupBy('work_type_id');

        // Дефолтная applicability — один раз
        $defaultApplicability = $this->defaultApplicability($tenantId);

        // 2. laborHourRate — один раз
        $laborRate = $user->role === UserRole::Manager
            ? $this->laborHourRateSummary($tenantId)
            : null;

        // 3. Мапа parent_id → parent (чтобы presentReason не делал запрос)
        $reasonsById = $reasons->keyBy('id');

        // 4. Готовим workTypes сразу с нормами
        $presentedWorkTypes = [];
        foreach ($workTypes as $workType) {
            $presentedWorkTypes[] = $this->presentWorkType(
                $workType,
                $user,
                $normsByWorkType->get($workType->id, collect()),
                $defaultApplicability,
                $laborRate,
            );
        }

        // 5. Готовим timeNorms отдельно (как самостоятельная секция)
        $presentedTimeNorms = [];
        foreach ($timeNorms as $norm) {
            $presentedTimeNorms[] = array_merge(
                [
                    'id'       => $norm->uuid,
                    'uuid'     => $norm->uuid,
                    'systemId' => $norm->id,
                    'kind'     => CatalogKind::TimeNorms->value,
                ],
                $this->presentTimeNorm($norm, $defaultApplicability),
                [
                    'permissions' => [
                        'canEdit'       => $user->role === UserRole::Manager,
                        'canCreateOnly' => false,
                    ],
                ],
            );
        }

        return [
            'supplierLegalForms' => collect(config('supplier_legal_forms'))->map(fn ($form, $code) => ['id' => $code, ...$form])->values()->all(),
            'partManufacturers' => $user->role === UserRole::Customer ? [] : \App\Models\PartManufacturer::query()->where('tenant_id', $tenantId)->where('status', 'approved')->where('active', true)->get()->map(fn ($row) => app(PartManufacturerService::class)->present($row, $user))->all(),
            'partUnits'     => config('sto.part_units'),
            'workGroups'    => config('sto.work_groups'),
            'organizations' => $user->role === UserRole::Manager
                ? Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->orderBy('kind')
                    ->orderBy('name')
                    ->get()
                    ->map(static fn (Organization $organization): array => [
                        'id'   => $organization->uuid,
                        'name' => $organization->name,
                        'kind' => $organization->kind->value,
                    ])
                    ->all()
                : [],
            'accessRights'  => $user->role === UserRole::Manager
                ? UserDirectoryService::rightOptions()
                : [],
            'reasons'       => $this->presentReasons($reasons, $reasonsById, $user),
            'workTypes'     => $presentedWorkTypes,
            'timeNorms'     => $presentedTimeNorms,
            'normApplicabilities' => $this->presentMany(
                CatalogKind::NormApplicabilities,
                TimeNormApplicability::query()
                    ->where('tenant_id', $tenantId)
                    ->where('active', true)
                    ->orderByDesc('is_system')
                    ->orderBy('name')
                    ->get(),
                $user,
            ),
            'regulations'   => $this->presentRegulations(
                MaintenanceRegulation::query()
                    ->where('tenant_id', $tenantId)
                    ->where('active', true)
                    ->get(),
            ),
            'suppliers'     => $user->role === UserRole::Customer
                ? []
                : $this->presentMany(
                    CatalogKind::Suppliers,
                    Supplier::query()
                        ->where('tenant_id', $tenantId)
                        ->where('active', true)
                        ->get(),
                    $user,
                ),
            'parts'         => $user->role === UserRole::Customer
                ? []
                : $this->presentMany(
                    CatalogKind::Parts,
                    CatalogPart::query()
                        ->where('tenant_id', $tenantId)
                        ->where('active', true)
                        ->get(),
                    $user,
                ),
        ];
    }

    public function records(User $user): array
    {
        if ($user->role === UserRole::Station) {
            return [
                ...\App\Models\PartManufacturer::query()->where('tenant_id', $user->tenantId())->get()->map(fn ($row) => app(PartManufacturerService::class)->present($row, $user))->all(),
                ...CatalogPart::query()->where('tenant_id', $user->tenantId())->get()->map(fn ($row) => $this->present(CatalogKind::Parts, $row, $user))->all(),
                ...Supplier::query()->where('tenant_id', $user->tenantId())->get()->map(fn ($row) => $this->present(CatalogKind::Suppliers, $row, $user))->all(),
            ];
        }

        $tenantId = $user->tenantId();
        $rows = [];

        // 1. Vehicles
        $vehicles = Vehicle::forUser($user)->with('organization')->get();
        foreach ($vehicles as $vehicle) {
            $rows[] = $this->vehicleService->present($vehicle, $user);
        }

        // 2. People
        foreach ($this->organizationPersonService->records($user) as $person) {
            $rows[] = $person;
        }

        // 3. Customer-specific pending
        if ($user->role === UserRole::Customer) {
            // ... оставляем как было
            return $rows;
        }

        // 4. Stations
        $stations = Station::query()->where('tenant_id', $tenantId)->get();
        foreach ($stations as $station) {
            $rows[] = $this->present(CatalogKind::Stations, $station, $user);
        }

        // 5. Customers (organizations)
        $customers = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', OrganizationKind::Customer->value)
            ->get();
        foreach ($customers as $customer) {
            $rows[] = $this->present(CatalogKind::Customers, $customer, $user);
        }

        // 6. Managers
        $managers = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', OrganizationKind::Manager->value)
            ->get();
        foreach ($managers as $manager) {
            $rows[] = $this->present(CatalogKind::Managers, $manager, $user);
        }

        // 7. Users
        foreach ($this->userDirectoryService->records($user) as $account) {
            $rows[] = $account;
        }

        // 8. Справочники — ОДНИМ блоком с eager load
        //    Сначала грузим всё, что нужно для presentWorkType/presentTimeNorm
        $defaultApplicability = $this->defaultApplicability($tenantId);

        $workTypes = WorkType::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->get();

        $timeNorms = TimeNorm::query()
            ->whereIn('work_type_id', $workTypes->pluck('id'))
            ->where('active', true)
            ->with($this->timeNormRelations())
            ->orderBy('id')
            ->get()
            ->groupBy('work_type_id');

        $reasons = ServiceReason::query()
            ->where('tenant_id', $tenantId)
            ->get();
        $reasonsById = $reasons->keyBy('id');

        // 9. Проходим по MODELS, но для workTypes/timeNorms/reasons
        //    используем уже загруженные данные
        foreach (self::MODELS as $kind => $class) {
            $catalogKind = CatalogKind::from($kind);

            if ($catalogKind === CatalogKind::WorkTypes) {
                foreach ($workTypes as $workType) {
                    $rows[] = array_merge(
                        [
                            'id'       => $workType->uuid,
                            'uuid'     => $workType->uuid,
                            'systemId' => $workType->id,
                            'kind'     => CatalogKind::WorkTypes->value,
                        ],
                        $this->presentWorkType(
                            $workType,
                            $user,
                            $timeNorms->get($workType->id, collect()),
                            $defaultApplicability,
                            $user->role === UserRole::Manager
                                ? $this->laborHourRateSummary($tenantId)
                                : null,
                        ),
                        [
                            'permissions' => [
                                'canEdit'       => $user->role === UserRole::Manager,
                                'canCreateOnly' => false,
                            ],
                        ],
                    );
                }
                continue;
            }

            if ($catalogKind === CatalogKind::TimeNorms) {
                foreach ($timeNorms->flatten(1) as $norm) {
                    $rows[] = array_merge(
                        [
                            'id'       => $norm->uuid,
                            'uuid'     => $norm->uuid,
                            'systemId' => $norm->id,
                            'kind'     => CatalogKind::TimeNorms->value,
                        ],
                        $this->presentTimeNorm($norm, $defaultApplicability),
                        [
                            'permissions' => [
                                'canEdit'       => $user->role === UserRole::Manager,
                                'canCreateOnly' => false,
                            ],
                        ],
                    );
                }
                continue;
            }

            if ($catalogKind === CatalogKind::Reasons) {
                foreach ($reasons as $reason) {
                    $rows[] = $this->presentReason($reason, $reasonsById, $user);
                }
                continue;
            }

            $query = $class::query()->where('tenant_id', $tenantId);

            if ($catalogKind === CatalogKind::NormApplicabilities) {
                $query->where('active', true)->orderByDesc('is_system')->orderBy('name');
            } elseif ($catalogKind === CatalogKind::Parts) {
                $query->where('active', true);
            } elseif ($catalogKind === CatalogKind::Suppliers) {
                $query->where('active', true);
            }

            foreach ($query->get() as $model) {
                $rows[] = $this->present($catalogKind, $model, $user);
            }
        }

        // 10. Pending changes
        foreach ($this->vehicleCatalogService->pending($user) as $change) {
            $rows[] = $change;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CatalogKind|string $kind, Model $model, User $user): array
    {
        $kind = $kind instanceof CatalogKind ? $kind : CatalogKind::from($kind);
        if ($kind === CatalogKind::PartManufacturers) {
            return app(PartManufacturerService::class)->present($model, $user);
        }

        $fields = match ($kind) {
            CatalogKind::Stations => $this->presentStation($model),
            CatalogKind::Customers => $this->presentCustomer($model),
            CatalogKind::Managers => $this->presentManager($model),
            CatalogKind::WorkTypes => $this->presentWorkType($model, $user),
            CatalogKind::TimeNorms => $this->presentTimeNorm($model),
            CatalogKind::NormApplicabilities => $this->presentNormApplicability($model),
            CatalogKind::Parts => $this->presentPart($model),
            CatalogKind::PartManufacturers => app(PartManufacturerService::class)->present($model, $user),
            CatalogKind::Reasons => $this->presentReason($model),
            CatalogKind::Regulations => $this->presentRegulation($model),
            CatalogKind::Suppliers => $this->presentSupplier($model),
            default       => [],
        };

        return array_merge(
            [
                'id'       => $model->uuid,
                'uuid'     => $model->uuid,
                'systemId' => $model->id,
                'kind'     => $kind->value,
            ],
            $fields,
            [
                'permissions' => [
                    'canEdit' => ($user->role === UserRole::Manager || ($user->role === UserRole::Station && ($kind === CatalogKind::Parts || ($kind === CatalogKind::Suppliers && $model->created_by_organization_id === $user->organization_id))))
                        && !(
                            $kind === CatalogKind::NormApplicabilities
                            && (bool) $model->is_system
                        ),
                    'canCreateOnly' => false,
                ],
            ],
        );
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    public function update(User $user, string $id, array $patch): array
    {
        $manufacturer = \App\Models\PartManufacturer::query()->where('tenant_id', $user->tenantId())->where('uuid', $id)->first();
        if ($manufacturer) {
            $this->documentLocks->assertAvailable($user, 'catalog_record', 'partManufacturers:' . $id);
            return app(PartManufacturerService::class)->write($user, $manufacturer, $patch);
        }
        if (!in_array($user->role, [UserRole::Manager, UserRole::Station], true)) {
            throw new ApiException(403, 'FORBIDDEN', 'Справочники редактируют УК и уполномоченные СТО');
        }

        if (!Str::isUuid($id)) {
            abort(404);
        }

        $catalog = [
            [CatalogKind::Users, User::class],
            [CatalogKind::Vehicles, Vehicle::class],
            [CatalogKind::Stations, Station::class],
            [CatalogKind::Customers, Organization::class],
            [CatalogKind::Managers, Organization::class],
        ];

        foreach (self::MODELS as $kind => $class) {
            $catalog[] = [CatalogKind::from($kind), $class];
        }

        foreach ($catalog as [$kind, $class]) {
            if ($kind === CatalogKind::Users) {
                $query = $class::query()->whereHas(
                    'organization',
                    fn ($organization) => $organization->where(
                        'tenant_id',
                        $user->tenantId(),
                    ),
                );
            } else {
                $query = $kind === CatalogKind::TimeNorms
                    ? $class::query()->whereIn(
                        'work_type_id',
                        $this->workTypeIdsSubquery($user->tenantId()),
                    )
                    : $class::query()->where('tenant_id', $user->tenantId());
            }

            if ($kind === CatalogKind::Customers) {
                $query->where('kind', OrganizationKind::Customer->value);
            }

            if ($kind === CatalogKind::Managers) {
                $query->where('kind', OrganizationKind::Manager->value);
            }

            $model = $query->where('uuid', $id)->first();

            if ($model !== null) {
                if ($kind === CatalogKind::Users) {
                    return $this->userDirectoryService->update(
                        $user,
                        $model,
                        $patch,
                    );
                }

                $this->documentLocks->assertAvailable(
                    $user,
                    'catalog_record',
                    $kind->value . ':' . $model->uuid,
                );

                if ($user->role === UserRole::Station && !in_array($kind, [CatalogKind::Parts, CatalogKind::Suppliers], true)) {
                    throw new ApiException(403, 'FORBIDDEN', 'Этот справочник редактирует только УК');
                }
                if ($kind === CatalogKind::Suppliers) {
                    return $this->present($kind, app(SupplierService::class)->write($user, $model, $patch), $user);
                }
                if ($kind === CatalogKind::Parts) {
                    $patch = array_replace(array_intersect_key($this->presentPart($model), $this->rulesFor($kind, false)), $patch);
                }

                if (
                    $kind === CatalogKind::NormApplicabilities
                    && (bool) $model->is_system
                ) {
                    throw new ApiException(
                        403,
                        'FORBIDDEN',
                        'Системную применимость нельзя изменить',
                    );
                }

                if (in_array($kind, [
                    CatalogKind::Customers,
                    CatalogKind::Stations,
                    CatalogKind::Managers,
                ], true)) {
                    return $this->writeParticipant($user, $kind, $model, $patch);
                }

                return $this->write($user, $kind, $model, $patch, false);
            }
        }

        abort(404);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function create(User $user, CatalogKind|string $kind, array $values): array
    {
        $kind = $kind instanceof CatalogKind ? $kind : CatalogKind::tryFrom($kind);
        if ($kind === CatalogKind::PartManufacturers) {
            return app(PartManufacturerService::class)->write($user, null, $values);
        }
        if ($user->role !== UserRole::Manager && !($user->role === UserRole::Station && in_array($kind, [CatalogKind::Parts, CatalogKind::Suppliers], true))) {
            throw new ApiException(403, 'FORBIDDEN', 'Справочники создаёт УК');
        }

        $kind = $kind instanceof CatalogKind ? $kind : CatalogKind::tryFrom($kind);

        if ($kind === null) {
            Input::fail('Неизвестный справочник');
        }

        if ($kind === CatalogKind::Suppliers) {
            return $this->present($kind, app(SupplierService::class)->write($user, null, $values), $user);
        }

        if ($kind === CatalogKind::Users) {
            return $this->userDirectoryService->create($user, $values);
        }

        if (in_array($kind, [
            CatalogKind::Customers,
            CatalogKind::Stations,
            CatalogKind::Managers,
        ], true)) {
            return $this->createParticipant($user, $kind, $values);
        }

        $class = self::MODELS[$kind->value] ?? null;

        if ($class === null) {
            Input::fail('Неизвестный справочник');
        }

        return $this->write($user, $kind, new $class(), $values, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(
        User $user,
        CatalogKind|string|null $kind = null,
        ?string $recordId = null,
    ): array {
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Журнал справочников доступен только УК',
            );
        }

        $kindValue = $kind instanceof CatalogKind ? $kind->value : $kind;

        if ($kindValue === '') {
            $kindValue = null;
        }

        if ($recordId !== null && !Str::isUuid($recordId)) {
            Input::fail('Некорректный UUID записи справочника');
        }

        return CatalogChangeEvent::query()
            ->with('actor')
            ->where('tenant_id', $user->tenantId())
            ->when(
                $kindValue !== null,
                fn ($query) => $query->where('catalog_kind', $kindValue),
            )
            ->when(
                $recordId !== null,
                fn ($query) => $query->where('record_uuid', $recordId),
            )
            ->latest('occurred_at')
            ->limit(200)
            ->get()
            ->map(static fn (CatalogChangeEvent $event): array => [
                'id'            => $event->uuid,
                'kind'          => $event->catalog_kind,
                'recordId'      => $event->record_uuid,
                'recordLabel'   => $event->record_label,
                'action'        => $event->action,
                'changedFields' => $event->changed_fields,
                'before'        => $event->before_payload,
                'after'         => $event->after_payload,
                'actor'         => $event->actor?->display_name ?? $event->actor?->login ?? 'Консоль',
                'occurredAt'    => $event->occurred_at->toISOString(),
            ])
            ->all();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function write(
        User $user,
        CatalogKind $kind,
        Model $model,
        array $payload,
        bool $create,
    ): array {
        return DB::transaction(function () use ($user, $kind, $model, $payload, $create): array {
            if (in_array($kind, [
                CatalogKind::WorkTypes,
                CatalogKind::TimeNorms,
            ], true)) {
                DB::table('tenants')
                    ->where('id', $user->tenantId())
                    ->lockForUpdate()
                    ->first();
            }

            $rules = $this->rulesFor($kind, $create);

            $payload = $this->normalizeEmptyStrings($rules, $payload);

            Input::validate($payload, $rules);

            $this->validateRequiredFields($payload, $kind, $create);

            if (!$create) {
                $model = $model->newQuery()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            }

            $before = $create ? null : $model->attributesToArray();

            $this->fillModel($model, $payload, $kind, $create, $user);

            $model->save();

            if (
                $kind === CatalogKind::WorkTypes
                && (array_key_exists('timeNorms', $payload)
                    || array_key_exists('deletedTimeNormIds', $payload))
            ) {
                $this->syncWorkTypeNorms(
                    $user,
                    $model,
                    $payload['timeNorms'] ?? [],
                    $payload['deletedTimeNormIds'] ?? [],
                );
            }

            if ($kind === CatalogKind::Regulations && isset($payload['items'])) {
                $this->syncRegulationItems($user, $model, $payload['items']);
            }

            $model->refresh();

            $this->audit->record(
                $user->tenantId(),
                $user,
                $kind,
                $model,
                $create ? 'create' : 'update',
                $before,
                $model->attributesToArray(),
            );

            return $kind === CatalogKind::Vehicles
                ? $this->vehicleService->present($model, $user)
                : $this->present($kind, $model, $user);
        });
    }

    /* Представление справочников. */

    /**
     * @param iterable<Model> $models
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentMany(CatalogKind $kind, iterable $models, User $user): array
    {
        $result = [];

        foreach ($models as $model) {
            $result[] = $this->present($kind, $model, $user);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStation(Model $station): array
    {
        $station->loadMissing('organization');

        return array_merge($station->profile ?? [], [
            'name'         => $station->name,
            'address'      => $station->address,
            'contact'      => $station->contact,
            'rating'       => $station->rating,
            'active'       => $station->active,
            'workdayStart' => $this->formatStationTime($station->workday_start),
            'workdayEnd'   => $this->formatStationTime($station->workday_end),
            'workingDays'  => array_values(array_map(
                static fn (mixed $day): int => (int) $day,
                $station->working_days ?? [],
            )),
        ], $this->presentOrganization($station->organization));
    }

    private function formatStationTime(mixed $value): string
    {
        return substr((string) $value, 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCustomer(Model $customer): array
    {
        return array_merge([
            'name'    => $customer->name,
            'contact' => $customer->contact,
        ], $this->presentOrganization($customer));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentManager(Model $manager): array
    {
        return array_merge([
            'name'    => $manager->name,
            'contact' => $manager->contact,
        ], $this->presentOrganization($manager));
    }

    /**
     * FIX: аргументы $timeNorms, $defaultApplicability, $laborRate стали
     * необязательными. В reference() они передаются явно (eager load),
     * а в старом present() — подгружаются fallback-ом (медленно, но совместимо).
     *
     * @param \Illuminate\Support\Collection<int, TimeNorm>|null $timeNorms
     */
    private function presentWorkType(
        Model $workType,
        User $user,
        $timeNorms = null,
        ?TimeNormApplicability $defaultApplicability = null,
        float|string|null $laborRate = null,
    ): array {
        if ($timeNorms === null) {
            $timeNorms = TimeNorm::query()
                ->where('work_type_id', $workType->id)
                ->where('active', true)
                ->with($this->timeNormRelations())
                ->orderBy('id')
                ->get();
        }

        if ($defaultApplicability === null) {
            $defaultApplicability = $this->defaultApplicability(
                (int) $workType->tenant_id,
            );
        }

        if ($laborRate === null && $user->role === UserRole::Manager) {
            $laborRate = $this->laborHourRateSummary((int) $workType->tenant_id);
        }

        $presentedNorms = [];
        foreach ($timeNorms as $norm) {
            $presentedNorms[] = array_merge(
                ['id' => $norm->uuid, 'kind' => CatalogKind::TimeNorms->value],
                $this->presentTimeNorm($norm, $defaultApplicability),
            );
        }

        return [
            'id'            => $workType->uuid,
            'uuid'          => $workType->uuid,
            'systemId'      => $workType->id,
            'kind'          => 'workTypes',
            'code'          => $workType->code,
            'groupCode'     => $workType->group_code,
            'name'          => $workType->name,
            'group'         => $workType->group_name,
            'isMaintenance' => $workType->is_maintenance,
            'active'        => $workType->active,
            'catalogVersion' => $workType->catalog_version,
            'archivedAt'    => $workType->archived_at?->toISOString(),
            'laborHourRate' => $laborRate,
            'timeNorms'     => $presentedNorms,
        ];
    }

    /**
     * FIX: $defaultApplicability теперь необязательный. Если relation-ы
     * не были eager-loaded — подгружаем их (fallback для старого present()).
     */
    private function presentTimeNorm(
        Model $timeNorm,
        ?TimeNormApplicability $defaultApplicability = null,
    ): array {
        $timeNorm->loadMissing($this->timeNormRelations());

        $workType = $timeNorm->workType;
        $applicability = $timeNorm->applicability
        ?? $defaultApplicability
            ?? $this->defaultApplicability((int) ($workType?->tenant_id ?? 0));

        return [
            'normId'            => $timeNorm->norm_id,
            'workTypeId'        => $workType?->uuid ?? '',
            'applicabilityId'   => $applicability->uuid ?? '',
            'applicability'     => $applicability->name ?? 'Общая',
            'applicabilityCode' => $applicability->code ?? 'general',
            'vehicleMakeId'     => $timeNorm->vehicleMake?->uuid ?? '',
            'vehicleMake'       => $timeNorm->vehicleMake?->name ?? '',
            'vehicleModelId'    => $timeNorm->vehicleModel?->uuid ?? '',
            'vehicleModel'      => $timeNorm->vehicleModel?->name
                ?? $timeNorm->vehicle_model,
            'vehicleId'         => $timeNorm->vehicle?->uuid ?? '',
            'vin'               => $timeNorm->vehicle?->vin ?? '',
            'vehicleLabel'      => implode(' · ', array_filter([
                $timeNorm->vehicle?->name,
                $timeNorm->vehicle?->vin !== null
                    ? 'VIN ' . $timeNorm->vehicle->vin
                    : null,
                $timeNorm->vehicle?->plate,
            ])),
            'vehicleGenerationId' => $timeNorm->vehicleGeneration?->uuid ?? '',
            'vehicleGeneration' => $timeNorm->vehicleGeneration?->name ?? '',
            'vehicleEngineId'   => $timeNorm->vehicleEngineVariant?->uuid ?? '',
            'vehicleEngine'     => $timeNorm->vehicleEngineVariant?->code ?? '',
            'vehicleTransmissionId' => $timeNorm->vehicleTransmissionType?->uuid ?? '',
            'vehicleTransmission' => $timeNorm->vehicleTransmissionType?->name ?? '',
            'vehicleDriveId'    => $timeNorm->vehicleDriveType?->uuid ?? '',
            'vehicleDrive'      => $timeNorm->vehicleDriveType?->name ?? '',
            'modificationCode'  => $timeNorm->modification_code ?? '',
            'displayCode'       => $this->normDisplayCode($timeNorm),
            'legacyVehicleModel'  => $timeNorm->vehicle_model,
            'hours'               => $timeNorm->hours !== null
                ? (float) $timeNorm->hours
                : null,
            'source'            => $timeNorm->source,
            'active'            => $timeNorm->active,
            'catalogVersion'    => $timeNorm->catalog_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentNormApplicability(Model $applicability): array
    {
        return [
            'name'     => $applicability->name,
            'code'     => $applicability->code,
            'isSystem' => $applicability->is_system,
            'active'   => $applicability->active,
        ];
    }

    private function laborHourRateSummary(int $tenantId): float|string|null
    {
        if (array_key_exists($tenantId, $this->laborHourRateSummaries)) {
            return $this->laborHourRateSummaries[$tenantId];
        }

        $rates = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereNotNull('labor_hour_rate')
            ->orderBy('labor_hour_rate')
            ->pluck('labor_hour_rate')
            ->map(static fn (mixed $rate): string => number_format((float) $rate, 2, '.', ''))
            ->unique()
            ->values();

        return $this->laborHourRateSummaries[$tenantId] = match (true) {
            $rates->isEmpty() => null,
            $rates->count() > 1 => 'Несколько',
            default => (float) $rates->first(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPart(Model $part): array
    {
        return [
            'name'         => $part->name,
            'article'      => $part->article,
            'manufacturer' => $part->manufacturer,
            'manufacturerId' => $part->manufacturerRecord?->uuid ?? '',
            'supplierId' => $part->supplierRecord?->uuid ?? '',
            'purchasePrice' => $part->purchase_price === null ? null : (float) $part->purchase_price,
            'createdByOrganizationId' => $part->creatorOrganization?->uuid ?? '',
            'createdByOrganization' => $part->created_by_organization_name ?? 'Не зафиксировано',
            'salePrice' => $part->sale_price === null ? null : (float) $part->sale_price,
            'unit'         => $part->unit,
            'active'       => $part->active,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, ServiceReason> $reasons
     * @param \Illuminate\Support\Collection<int, ServiceReason>|null $reasonsById
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentReasons($reasons, $reasonsById, User $user): array
    {
        $result = [];
        foreach ($reasons as $reason) {
            $result[] = $this->presentReason($reason, $reasonsById, $user);
        }
        return $result;
    }

    /**
     * FIX: $reasonsById и $user стали необязательными. В reference() они
     * передаются явно (мапа parent_id → parent), в старом present() —
     * fallback делает один запрос за parent (как было раньше).
     *
     * @param \Illuminate\Support\Collection<int, ServiceReason>|null $reasonsById
     */
    private function presentReason(
        Model $reason,
        $reasonsById = null,
        ?User $user = null,
    ): array {
        if ($reasonsById === null) {
            $parent = $reason->parent_id !== null
                ? ServiceReason::query()->find($reason->parent_id)
                : null;
        } else {
            $parent = $reason->parent_id !== null
                ? $reasonsById->get($reason->parent_id)
                : null;
        }

        $role = $user?->role ?? UserRole::Customer;

        return array_merge(
            [
                'id'       => $reason->uuid,
                'uuid'     => $reason->uuid,
                'systemId' => $reason->id,
                'kind'     => CatalogKind::Reasons->value,
            ],
            [
                'name'              => $reason->name,
                'code'              => $reason->code,
                'parentId'          => $parent?->uuid ?? '',
                'requiresDiagnosis' => $reason->requires_diagnosis,
                'isMaintenance'     => $reason->is_maintenance,
                'active'            => $reason->active,
            ],
            [
                'permissions' => [
                    'canEdit'       => $role === UserRole::Manager,
                    'canCreateOnly' => false,
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRegulation(Model $regulation): array
    {
        $items = MaintenanceRegulationItem::query()
            ->where('maintenance_regulation_id', $regulation->id)
            ->with(['workType', 'timeNorm'])
            ->get()
            ->map(static function (Model $item): array {
                return [
                    'workTypeId' => $item->workType?->uuid ?? '',
                    'timeNormId' => $item->timeNorm?->uuid ?? '',
                    'qty'        => (float) $item->quantity,
                ];
            })
            ->all();

        return [
            'name'           => $regulation->name,
            'vehicleModel'   => $regulation->vehicle_model,
            'intervalKm'     => $regulation->interval_km,
            'intervalMonths' => $regulation->interval_months,
            'source'         => $regulation->source,
            'active'         => $regulation->active,
            'items'          => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSupplier(Model $supplier): array
    {
        return array_merge($supplier->requisites ?? [], [
            'name' => $supplier->name, 'contact' => $supplier->contact,
            'taxId' => $supplier->tax_id, 'active' => $supplier->active,
            'legalForm' => $supplier->legal_form ?? '',
            'contacts' => $supplier->contacts ?? [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrganization(Organization $organization): array
    {
        $result = [];

        foreach (self::ORGANIZATION_FIELDS as $field => $attribute) {
            $value = $organization->{$attribute};
            $result[$field] = $value === null
                ? null
                : ($field === 'vatRate' ? (float) $value : $value);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function createParticipant(User $user, CatalogKind $kind, array $payload): array
    {
        $payload = $this->validateParticipant($kind, $payload, true);

        return DB::transaction(function () use ($user, $kind, $payload): array {
            $organization = Organization::query()->create(array_merge([
                'tenant_id' => $user->tenantId(),
                'kind'      => match ($kind) {
                    CatalogKind::Customers => OrganizationKind::Customer,
                    CatalogKind::Stations => OrganizationKind::Station,
                    default => OrganizationKind::Manager,
                },
                'name'      => trim((string) $payload['name']),
                'contact'   => (string) ($payload['contact'] ?? ''),
            ], $this->organizationAttributes($payload)));

            if ($kind !== CatalogKind::Stations) {
                $this->audit->record(
                    $user->tenantId(),
                    $user,
                    $kind,
                    $organization,
                    'create',
                    null,
                    $organization->attributesToArray(),
                );

                return $this->present($kind, $organization->refresh(), $user);
            }

            $station = Station::query()->create([
                'tenant_id'       => $user->tenantId(),
                'organization_id' => $organization->id,
                'name'            => $organization->name,
                'address'         => (string) ($payload['address'] ?? ''),
                'contact'         => (string) ($payload['contact'] ?? ''),
                'active'          => (bool) ($payload['active'] ?? true),
                'workday_start'   => $payload['workdayStart'],
                'workday_end'     => $payload['workdayEnd'],
                'working_days'    => $payload['workingDays'],
                'profile'         => $this->stationProfile($payload),
            ]);

            $this->audit->record(
                $user->tenantId(),
                $user,
                $kind,
                $station,
                'create',
                null,
                array_merge(
                    $station->attributesToArray(),
                    $organization->attributesToArray(),
                ),
            );

            return $this->present(CatalogKind::Stations, $station->refresh(), $user);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function writeParticipant(
        User $user,
        CatalogKind $kind,
        Model $model,
        array $payload,
    ): array {
        $payload = $this->validateParticipant($kind, $payload, false);

        return DB::transaction(function () use ($user, $kind, $model, $payload): array {
            $model = $model->newQuery()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $organization = $kind === CatalogKind::Stations
                ? Organization::query()
                    ->whereKey($model->organization_id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : $model;
            $before = array_merge(
                $model->attributesToArray(),
                $organization->attributesToArray(),
            );

            if (isset($payload['name'])) {
                $organization->name = trim((string) $payload['name']);

                if ($kind === CatalogKind::Stations) {
                    $model->name = $organization->name;
                }
            }

            if (array_key_exists('contact', $payload)) {
                $organization->contact = $payload['contact'];

                if ($kind === CatalogKind::Stations) {
                    $model->contact = $payload['contact'];
                }
            }

            foreach ($this->organizationAttributes($payload) as $attribute => $value) {
                $organization->{$attribute} = $value;
            }

            $organization->save();

            if ($kind === CatalogKind::Stations) {
                foreach (['address', 'active'] as $field) {
                    if (array_key_exists($field, $payload)) {
                        $model->{$field} = $payload[$field];
                    }
                }

                foreach ([
                    'workdayStart' => 'workday_start',
                    'workdayEnd'   => 'workday_end',
                    'workingDays'  => 'working_days',
                ] as $field => $attribute) {
                    if (array_key_exists($field, $payload)) {
                        $model->{$attribute} = $payload[$field];
                    }
                }

                $this->assertStationHours($payload, $model);

                $model->profile = array_replace(
                    $model->profile ?? [],
                    $this->stationProfile($payload),
                );
                $model->save();
            }

            $after = array_merge(
                $model->attributesToArray(),
                $organization->attributesToArray(),
            );

            $this->audit->record(
                $user->tenantId(),
                $user,
                $kind,
                $model,
                'update',
                $before,
                $after,
            );

            return $this->present($kind, $model->refresh(), $user);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validateParticipant(CatalogKind $kind, array $payload, bool $create): array
    {
        foreach ([
            'inn',
            'kpp',
            'ogrn',
            'email',
            'bik',
            'settlementAccount',
            'correspondentAccount',
        ] as $field) {
            if (($payload[$field] ?? null) === '') {
                $payload[$field] = null;
            }
        }

        $text = ['sometimes', 'string', 'max:1000'];
        $rules = [
            'name'                  => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'contact'               => $text,
            'shortName'             => $text,
            'inn'                   => ['sometimes', 'nullable', 'string', 'regex:/^(?:[0-9]{10}|[0-9]{12})$/'],
            'kpp'                   => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{9}$/'],
            'ogrn'                  => ['sometimes', 'nullable', 'string', 'regex:/^(?:[0-9]{13}|[0-9]{15})$/'],
            'legalAddress'          => $text,
            'actualAddress'         => $text,
            'postalAddress'         => $text,
            'directorName'          => $text,
            'phone'                 => ['sometimes', 'string', 'max:30'],
            'email'                 => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'bankName'              => $text,
            'bik'                   => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{9}$/'],
            'settlementAccount'     => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{20}$/'],
            'correspondentAccount'  => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{20}$/'],
            'taxRegime'             => $text,
            'vatRate'               => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        if ($kind === CatalogKind::Stations) {
            $rules['address'] = $text;
            $rules['active'] = ['sometimes', 'boolean'];
            $rules['workdayStart'] = [
                $create ? 'required' : 'sometimes',
                'required',
                'string',
                'date_format:H:i',
            ];
            $rules['workdayEnd'] = [
                $create ? 'required' : 'sometimes',
                'required',
                'string',
                'date_format:H:i',
            ];
            $rules['workingDays'] = [
                $create ? 'required' : 'sometimes',
                'required',
                'array',
                'list',
                'min:1',
                'max:7',
            ];
            $rules['workingDays.*'] = [
                'required',
                'integer',
                'between:1,7',
                'distinct',
            ];

            foreach (self::STATION_PROFILE_FIELDS as $field) {
                $rules[$field] = in_array($field, [
                    'mechanicalBays',
                    'lifts',
                    'mechanicsCount',
                    'electriciansCount',
                    'electricianBays',
                    'alignmentBays',
                    'engineBays',
                    'paintBooths',
                    'frameRacks',
                    'bodyworkBays',
                    'yearsOperating',
                ], true)
                    ? ['sometimes', 'integer', 'min:0', 'max:10000']
                    : (in_array($field, [
                        'slaDescription',
                        'vehicleRestrictions',
                        'customerAmenities',
                        'uniqueServices',
                    ], true)
                        ? $text
                        : ['sometimes', 'boolean']);
            }
        }

        $validated = Input::validate($payload, $rules);

        if ($kind === CatalogKind::Stations && $create) {
            $this->assertStationHours($validated);
        }

        if ($kind === CatalogKind::Stations && isset($validated['workingDays'])) {
            sort($validated['workingDays']);
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertStationHours(
        array $payload,
        ?Station $station = null,
    ): void {
        $start = (string) (
            $payload['workdayStart']
            ?? $this->formatStationTime($station?->workday_start)
        );
        $end = (string) (
            $payload['workdayEnd']
            ?? $this->formatStationTime($station?->workday_end)
        );

        if ($start >= $end) {
            Input::fail(
                'Время окончания работы должно быть позже '
                . 'времени начала',
                'workdayEnd',
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function organizationAttributes(array $payload): array
    {
        $result = [];

        foreach (self::ORGANIZATION_FIELDS as $field => $attribute) {
            if (array_key_exists($field, $payload)) {
                $result[$attribute] = $field === 'vatRate'
                    ? $payload[$field]
                    : ($payload[$field] ?? '');
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function stationProfile(array $payload): array
    {
        return array_intersect_key($payload, array_flip(self::STATION_PROFILE_FIELDS));
    }

    /* Правила и валидация. */

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(CatalogKind $kind, bool $create): array
    {
        $text = ['sometimes', 'string', 'max:1000'];
        $bool = ['sometimes', 'boolean'];

        return match ($kind) {
            CatalogKind::Vehicles => [
                'vehicle'               => $text,
                'vin'                   => ['sometimes', 'required', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
                'plate'                 => $text,
                'year'                  => ['nullable', 'integer', 'between:1900,2100'],
                'baselineServiceMileage' => ['nullable', 'integer', 'min:0', 'max:999999999'],
                'baselineServiceAt'     => ['nullable', 'date'],
                'laborRateCoefficient'  => ['sometimes', 'numeric', 'min:0.001', 'max:100', Input::numeric(3)],
            ],
            CatalogKind::Stations => [
                'name'    => $text,
                'address' => $text,
                'contact' => $text,
                'active'  => $bool,
            ],
            CatalogKind::Customers => [
                'name'    => $text,
                'contact' => $text,
            ],
            CatalogKind::WorkTypes => [
                'name'          => $text,
                'group'         => [
                    'sometimes',
                    'string',
                    'in:' . implode(',', config('sto.work_groups')),
                ],
                'isMaintenance' => $bool,
                'active'        => $bool,
                'timeNorms'     => ['sometimes', 'array', 'list', 'max:1000'],
                'deletedTimeNormIds' => [
                    'sometimes',
                    'array',
                    'list',
                    'max:1000',
                ],
                'deletedTimeNormIds.*' => ['uuid', 'distinct'],
                'timeNorms.*'   => [
                    'array:id,applicabilityId,vehicleMakeId,vehicleModelId,'
                    . 'vehicleId,vehicleGenerationId,vehicleEngineId,'
                    . 'vehicleTransmissionId,vehicleDriveId,modificationCode,'
                    . 'legacyVehicleModel,hours,source,active',
                ],
                'timeNorms.*.id'              => ['sometimes', 'string'],
                'timeNorms.*.applicabilityId' => ['required', 'uuid'],
                'timeNorms.*.vehicleMakeId'   => Input::uuidOrEmpty(),
                'timeNorms.*.vehicleModelId'  => Input::uuidOrEmpty(),
                'timeNorms.*.vehicleId'       => Input::uuidOrEmpty(),
                'timeNorms.*.vehicleGenerationId' => Input::uuidOrEmpty(),
                'timeNorms.*.vehicleEngineId' => Input::uuidOrEmpty(),
                'timeNorms.*.vehicleTransmissionId' => Input::uuidOrEmpty(),
                'timeNorms.*.vehicleDriveId'  => Input::uuidOrEmpty(),
                'timeNorms.*.modificationCode' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:100',
                ],
                'timeNorms.*.legacyVehicleModel' => [
                    'sometimes',
                    'string',
                    'max:1000',
                ],
                'timeNorms.*.hours'            => [
                    'nullable',
                    'numeric',
                    'min:0.001',
                    'max:100000',
                    Input::numeric(3),
                ],
                'timeNorms.*.source'           => ['present', 'string', 'max:10000'],
                'timeNorms.*.active'           => ['sometimes', 'boolean'],
            ],
            CatalogKind::TimeNorms => [
                'workTypeId'     => [$create ? 'required' : 'sometimes', 'required', 'uuid'],
                'applicabilityId' => ['sometimes', 'uuid'],
                'vehicleMakeId'   => Input::uuidOrEmpty(),
                'vehicleModelId'  => Input::uuidOrEmpty(),
                'vehicleId'       => Input::uuidOrEmpty(),
                'vehicleGenerationId' => Input::uuidOrEmpty(),
                'vehicleEngineId' => Input::uuidOrEmpty(),
                'vehicleTransmissionId' => Input::uuidOrEmpty(),
                'vehicleDriveId'  => Input::uuidOrEmpty(),
                'modificationCode' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:100',
                ],
                'vehicleModel'    => $text,
                'hours'           => [
                    'sometimes',
                    'nullable',
                    'numeric',
                    'min:0.001',
                    'max:100000',
                    Input::numeric(3),
                ],
                'source'          => $text,
                'active'          => $bool,
            ],
            CatalogKind::NormApplicabilities => [
                'name'   => $text,
                'active' => $bool,
            ],
            CatalogKind::Parts => [
                'manufacturerId' => ['required', 'uuid'],
                'supplierId' => ['required', 'uuid'],
                'purchasePrice' => ['required', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
                'salePrice' => ['required', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
                'name' => ['required', 'string', 'max:500'],
                'article'      => ['required', 'string', 'max:100'],
                'manufacturer' => $text,
                'unit'         => ['required', 'string', \Illuminate\Validation\Rule::in(config('sto.part_units'))],
                'active'       => $bool,
            ],
            CatalogKind::Reasons => [
                'name'              => $text,
                'code'              => ['sometimes', 'string', 'regex:/^[a-z0-9_-]+$/', 'max:100'],
                'parentId'          => Input::uuidOrEmpty(),
                'requiresDiagnosis' => $bool,
                'isMaintenance'     => $bool,
                'active'            => $bool,
            ],
            CatalogKind::Regulations => [
                'name'             => $text,
                'vehicleModel'     => $text,
                'intervalKm'       => ['nullable', 'integer', 'min:1'],
                'intervalMonths'   => ['nullable', 'integer', 'min:1'],
                'source'           => $text,
                'active'           => $bool,
                'items'            => ['sometimes', 'array', 'list', 'max:100'],
                'items.*'          => ['array:workTypeId,timeNormId,qty'],
                'items.*.workTypeId' => ['required', 'uuid'],
                'items.*.timeNormId' => Input::uuidOrEmpty(),
                'items.*.qty'      => ['required', 'numeric', 'min:0.001', 'max:100000'],
            ],
            CatalogKind::Suppliers => [
                'name'    => $text,
                'contact' => $text,
                'taxId'   => $text,
                'active'  => $bool,
            ],
            default => [],
        };
    }

    /**
     * Пустые строки → null для nullable-полей.
     *
     * @param array<string, array<int, mixed>> $rules
     * @param array<string, mixed>            $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeEmptyStrings(array $rules, array $payload): array
    {
        foreach ($rules as $key => $rule) {
            if (
                in_array('nullable', $rule, true)
                && array_key_exists($key, $payload)
                && $payload[$key] === ''
            ) {
                $payload[$key] = null;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateRequiredFields(
        array $payload,
        CatalogKind $kind,
        bool $create,
    ): void {
        foreach (['name', 'vehicle'] as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) === '') {
                Input::fail('Название не может быть пустым');
            }
        }

        if (
            $create
            && $kind !== CatalogKind::TimeNorms
            && trim((string) ($payload['name'] ?? '')) === ''
        ) {
            Input::fail('Укажите название');
        }
    }

    /* Заполнение моделей. */

    /**
     * @param array<string, mixed> $payload
     */
    private function fillModel(
        Model $model,
        array $payload,
        CatalogKind $kind,
        bool $create,
        User $user,
    ): void {
        $map = [
            'vehicle'                => 'name',
            'plate'                  => 'plate',
            'year'                   => 'year',
            'vin'                    => 'vin',
            'name'                   => 'name',
            'address'                => 'address',
            'contact'                => 'contact',
            'active'                 => 'active',
            'isMaintenance'          => 'is_maintenance',
            'group'                  => 'group_name',
            'requiresDiagnosis'      => 'requires_diagnosis',
            'vehicleModel'           => 'vehicle_model',
            'hours'                  => 'hours',
            'source'                 => 'source',
            'code'                   => 'code',
            'intervalKm'             => 'interval_km',
            'intervalMonths'         => 'interval_months',
            'taxId'                  => 'tax_id',
            'baselineServiceMileage' => 'baseline_service_mileage',
            'baselineServiceAt'      => 'baseline_service_at',
            'laborRateCoefficient'   => 'labor_rate_coefficient',
            'article'                => 'article',
            'manufacturer'           => 'manufacturer',
            'unit'                   => 'unit',
        ];

        foreach ($payload as $key => $value) {
            if (isset($map[$key])) {
                $model->{$map[$key]} = $value;
            }
        }

        if ($kind !== CatalogKind::TimeNorms && $create) {
            $model->tenant_id = $user->tenantId();
        }

        match ($kind) {
            CatalogKind::Parts => $this->fillPart($model, $payload, $user),
            CatalogKind::Vehicles => $this->fillVehicle($model),
            CatalogKind::WorkTypes => $this->fillWorkType($model, $create, $user),
            CatalogKind::TimeNorms => $this->fillTimeNorm($model, $payload, $user),
            CatalogKind::NormApplicabilities => $this->fillNormApplicability(
                $model,
                $user,
            ),
            CatalogKind::Reasons => $this->fillReason($model, $payload, $user, $create),
            default     => null,
        };
    }

    private function fillPart(Model $model, array $payload, User $user): void
    {
        if (!$model->exists) {
            $model->created_by_organization_id = $user->organization_id;
            $model->created_by_organization_name = $user->organization->name;
        }
        $manufacturer = \App\Models\PartManufacturer::query()->where('tenant_id', $user->tenantId())->where('uuid', $payload['manufacturerId'])->where('status', 'approved')->where('active', true)->first();
        if (!$manufacturer) {
            Input::fail('Выберите производителя, согласованного УК', 'manufacturerId');
        }
        $supplier = Supplier::query()->where('tenant_id', $user->tenantId())->where('uuid', $payload['supplierId'])->where('active', true)->first();
        if (!$supplier) {
            Input::fail('Выберите действующего поставщика', 'supplierId');
        }
        if (trim((string) ($payload['article'] ?? '')) === '') {
            Input::fail('Укажите артикул', 'article');
        }
        $model->part_manufacturer_id = $manufacturer->id;
        $model->manufacturer = $manufacturer->name;
        $model->supplier_id = $supplier->id;
        $model->purchase_price = $payload['purchasePrice'];
        $model->sale_price = $payload['salePrice'];
    }

    private function fillVehicle(Model $model): void
    {
        $model->plate = mb_strtoupper((string) $model->plate);
        $model->plate_normalized = Vehicle::plateKey($model->plate);

        $duplicateVin = Vehicle::query()
            ->where('vin', $model->vin)
            ->where('id', '!=', $model->id)
            ->exists();

        if ($duplicateVin) {
            Input::fail('VIN уже существует');
        }
    }

    private function fillWorkType(Model $model, bool $create, User $user): void
    {
        if (!$create) {
            return;
        }

        $group = (string) ($model->group_name ?: 'Прочее');
        $prefix = WorkType::groupCodeFor($group);
        $lastCode = WorkType::query()
            ->where('tenant_id', $user->tenantId())
            ->where('code', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');
        $next = $lastCode === null
            ? 1
            : ((int) substr((string) $lastCode, 3)) + 1;

        if ($next > 99999) {
            throw new ApiException(
                409,
                'WORK_CODE_RANGE_EXHAUSTED',
                'Исчерпан диапазон кодов группы работ',
            );
        }

        $model->code = sprintf('%s-%05d', $prefix, $next);
        $model->group_code = $prefix;
        $model->group_name = $group;
        $model->catalog_version = 'manual';
        $model->source_name = 'Ручное создание в ЛК УК';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fillTimeNorm(Model $model, array $payload, User $user): void
    {
        if (isset($payload['workTypeId'])) {
            $workType = WorkType::query()
                ->where('tenant_id', $user->tenantId())
                ->where('uuid', $payload['workTypeId'])
                ->firstOrFail();

            $model->work_type_id = $workType->id;
        }

        if ($model->work_type_id === null) {
            return;
        }

        $workType = WorkType::query()
            ->where('tenant_id', $user->tenantId())
            ->findOrFail($model->work_type_id);
        $model->tenant_id = $user->tenantId();

        if (!$model->exists && empty($model->norm_id)) {
            $model->norm_id = 'MN-' . strtoupper(substr(
                str_replace('-', '', (string) Str::uuid()),
                0,
                16,
            ));
            $model->catalog_version = 'manual';
            $model->active = true;
        }

        $legacyVehicleModel = trim((string) (
            $payload['legacyVehicleModel']
            ?? $payload['vehicleModel']
            ?? ''
        ));
        $applicability = isset($payload['applicabilityId'])
            ? TimeNormApplicability::query()
                ->where('tenant_id', $user->tenantId())
                ->where('uuid', $payload['applicabilityId'])
                ->firstOrFail()
            : ($model->applicability
                ?? ($legacyVehicleModel !== ''
                    ? $this->applicabilityByCode(
                        $user->tenantId(),
                        'special',
                        'Специальная',
                    )
                    : $this->defaultApplicability($user->tenantId())));

        $make = array_key_exists('vehicleMakeId', $payload)
            ? (empty($payload['vehicleMakeId'])
                ? null
                : VehicleMake::query()
                    ->where('uuid', $payload['vehicleMakeId'])
                    ->firstOrFail())
            : $model->vehicleMake;
        $vehicleModel = array_key_exists('vehicleModelId', $payload)
            ? (empty($payload['vehicleModelId'])
                ? null
                : VehicleModel::query()
                    ->with('make')
                    ->where('uuid', $payload['vehicleModelId'])
                    ->firstOrFail())
            : $model->vehicleModel;

        if ($vehicleModel !== null && $make === null) {
            $make = $vehicleModel->make;
        }

        if (
            $vehicleModel !== null
            && $make !== null
            && $vehicleModel->vehicle_make_id !== $make->id
        ) {
            Input::fail('Модель не относится к выбранной марке', 'vehicleModelId');
        }

        if ($applicability->code !== 'vehicle') {
            $make = null;
            $vehicleModel = null;
        }

        $model->work_type_id = $workType->id;
        $model->applicability_id = $applicability->id;
        $model->vehicle_make_id = $make?->id;
        $model->vehicle_model_id = $vehicleModel?->id;
        $model->vehicle_model = $applicability->code === 'vehicle'
            ? ($vehicleModel?->name ?? $make?->name ?? '')
            : (array_key_exists('legacyVehicleModel', $payload)
                || array_key_exists('vehicleModel', $payload)
                ? $legacyVehicleModel
                : (string) ($model->vehicle_model ?? ''));

        if ($applicability->code === 'vehicle') {
            $this->fillDetailedNormApplicability(
                $model,
                $payload,
                $user,
                $make,
                $vehicleModel,
            );
        } else {
            $this->clearDetailedNormApplicability($model);
        }

        if (array_key_exists('active', $payload)) {
            $model->active = (bool) $payload['active'];
        }

        $duplicate = TimeNorm::query()
            ->where('work_type_id', $model->work_type_id)
            ->where('applicability_id', $model->applicability_id)
            ->where('vehicle_make_id', $model->vehicle_make_id)
            ->where('vehicle_model_id', $model->vehicle_model_id)
            ->where('vehicle_id', $model->vehicle_id)
            ->where('vehicle_generation_id', $model->vehicle_generation_id)
            ->where('vehicle_engine_variant_id', $model->vehicle_engine_variant_id)
            ->where('vehicle_transmission_type_id', $model->vehicle_transmission_type_id)
            ->where('vehicle_drive_type_id', $model->vehicle_drive_type_id)
            ->where('modification_code', $model->modification_code)
            ->where('vehicle_model', $model->vehicle_model)
            ->where('active', true)
            ->when(
                $model->exists,
                fn ($query) => $query->where('id', '!=', $model->getKey()),
            )
            ->exists();

        if ($duplicate) {
            Input::fail('Норма с такой применимостью уже существует');
        }

        if ((bool) $model->active) {
            $this->assertNoTimeNormConflict($model);
        }
    }

    private function assertNoTimeNormConflict(Model $model): void
    {
        $priority = $this->timeNormPriority($model);
        $candidates = TimeNorm::query()
            ->where('tenant_id', $model->tenant_id)
            ->where('work_type_id', $model->work_type_id)
            ->where('active', true)
            ->when(
                $model->exists,
                fn ($query) => $query->where(
                    $model->getKeyName(),
                    '!=',
                    $model->getKey(),
                ),
            )
            ->get();

        foreach ($candidates as $candidate) {
            if (
                $this->timeNormPriority($candidate) === $priority
                && $this->timeNormsOverlap($model, $candidate)
            ) {
                Input::fail(
                    'Норма пересекается без однозначного приоритета с '
                    . $candidate->norm_id,
                    'applicabilityId',
                );
            }
        }
    }

    private function timeNormPriority(Model $model): string
    {
        $rank = $model->vehicle_id !== null
            ? 500
            : ($model->vehicle_generation_id !== null
                ? 400
                : ($model->vehicle_make_id !== null
                    && $model->vehicle_model_id !== null
                    ? 300
                    : ($model->vehicle_make_id !== null ? 200 : 100)));
        $constraints = $rank === 400
            ? count(array_filter(
                $this->timeNormDimensions($model),
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ))
            : 0;

        return $rank . ':' . $constraints;
    }

    private function timeNormsOverlap(Model $left, Model $right): bool
    {
        $leftDimensions = $this->timeNormDimensions($left);
        $rightDimensions = $this->timeNormDimensions($right);

        foreach ($leftDimensions as $field => $leftValue) {
            $rightValue = $rightDimensions[$field];

            if (
                $leftValue !== null
                && $leftValue !== ''
                && $rightValue !== null
                && $rightValue !== ''
                && (string) $leftValue !== (string) $rightValue
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function timeNormDimensions(Model $model): array
    {
        return [
            'vehicle_id' => $model->vehicle_id,
            'vehicle_make_id' => $model->vehicle_make_id,
            'vehicle_model_id' => $model->vehicle_model_id,
            'vehicle_generation_id' => $model->vehicle_generation_id,
            'vehicle_engine_variant_id' => $model->vehicle_engine_variant_id,
            'vehicle_transmission_type_id' => $model->vehicle_transmission_type_id,
            'vehicle_drive_type_id' => $model->vehicle_drive_type_id,
            'modification_code' => $model->modification_code,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fillDetailedNormApplicability(
        Model $model,
        array $payload,
        User $user,
        ?VehicleMake $make,
        ?VehicleModel $vehicleModel,
    ): void {
        $vehicleId = $this->referenceId(
            $payload,
            'vehicleId',
            $model->vehicle_id,
            Vehicle::class,
            $user->tenantId(),
        );
        $generationId = $this->referenceId(
            $payload,
            'vehicleGenerationId',
            $model->vehicle_generation_id,
            VehicleGeneration::class,
        );
        $engineId = $this->referenceId(
            $payload,
            'vehicleEngineId',
            $model->vehicle_engine_variant_id,
            VehicleEngineVariant::class,
        );
        $transmissionId = $this->referenceId(
            $payload,
            'vehicleTransmissionId',
            $model->vehicle_transmission_type_id,
            VehicleTransmissionType::class,
        );
        $driveId = $this->referenceId(
            $payload,
            'vehicleDriveId',
            $model->vehicle_drive_type_id,
            VehicleDriveType::class,
        );
        $modificationCode = array_key_exists('modificationCode', $payload)
            ? trim((string) ($payload['modificationCode'] ?? ''))
            : trim((string) ($model->modification_code ?? ''));

        $generation = $generationId === null
            ? null
            : VehicleGeneration::query()->findOrFail($generationId);
        $engine = $engineId === null
            ? null
            : VehicleEngineVariant::query()->findOrFail($engineId);
        $vehicle = $vehicleId === null
            ? null
            : Vehicle::query()->findOrFail($vehicleId);
        $vehicle?->loadMissing('modelReference.make');
        $contextModel = $vehicleModel ?? $vehicle?->modelReference;
        $contextMake = $make ?? $contextModel?->make;

        if ($generation !== null && $contextModel === null) {
            Input::fail('Для поколения выберите марку и модель', 'vehicleGenerationId');
        }

        if (
            $generation !== null
            && !VehicleReferenceIdentity::sameModel($generation->model, $contextModel)
        ) {
            Input::fail(
                'Поколение не относится к выбранной модели',
                'vehicleGenerationId',
            );
        }

        if ($engine !== null && $contextModel === null) {
            Input::fail('Для двигателя выберите марку и модель', 'vehicleEngineId');
        }

        if ($engine !== null && !VehicleReferenceIdentity::sameModel($engine->model, $contextModel)) {
            Input::fail(
                'Двигатель не относится к выбранной модели',
                'vehicleEngineId',
            );
        }

        if (
            $generation !== null
            && $engine?->vehicle_generation_id !== null
            && !VehicleReferenceIdentity::sameGeneration($engine->generation, $generation)
        ) {
            Input::fail(
                'Двигатель не относится к выбранному поколению',
                'vehicleEngineId',
            );
        }

        $hasModification = $engineId !== null
            || $transmissionId !== null
            || $driveId !== null
            || $modificationCode !== '';

        if ($hasModification && $generationId === null && $vehicleId === null) {
            Input::fail(
                'Для модификации выберите поколение или конкретное ТС',
                'vehicleGenerationId',
            );
        }

        if ($vehicle !== null) {
            $mismatch = ($vehicleModel !== null
                    && !VehicleReferenceIdentity::sameModel($vehicle->modelReference, $vehicleModel))
                || ($make !== null
                    && $vehicle->modelReference?->vehicle_make_id !== $make->id)
                || ($generationId !== null
                    && !VehicleReferenceIdentity::sameGeneration($vehicle->generationReference, $generation))
                || ($engineId !== null
                    && !VehicleReferenceIdentity::sameEngine($vehicle->engineReference, $engine))
                || ($transmissionId !== null
                    && $vehicle->vehicle_transmission_type_id !== $transmissionId)
                || ($driveId !== null
                    && $vehicle->vehicle_drive_type_id !== $driveId);

            if ($mismatch) {
                Input::fail(
                    'Признаки применимости не соответствуют выбранному ТС',
                    'vehicleId',
                );
            }
        }

        if (
            $vehicleId === null
            && $contextMake === null
            && $contextModel === null
            && $generationId === null
            && !$hasModification
        ) {
            Input::fail(
                'Для применимости «По авто» выберите марку и/или модель ТС '
                . 'либо конкретное ТС',
                'vehicleMakeId',
            );
        }

        $model->vehicle_id = $vehicleId;
        $model->vehicle_generation_id = $generationId;
        $model->vehicle_engine_variant_id = $engineId;
        $model->vehicle_transmission_type_id = $transmissionId;
        $model->vehicle_drive_type_id = $driveId;
        $model->modification_code = $modificationCode === ''
            ? null
            : $modificationCode;
    }

    private function clearDetailedNormApplicability(Model $model): void
    {
        $model->vehicle_id = null;
        $model->vehicle_generation_id = null;
        $model->vehicle_engine_variant_id = null;
        $model->vehicle_transmission_type_id = null;
        $model->vehicle_drive_type_id = null;
        $model->modification_code = null;
    }

    /** @param class-string<Model> $class */
    private function referenceId(
        array $payload,
        string $field,
        ?int $currentId,
        string $class,
        ?int $tenantId = null,
    ): ?int {
        if (!array_key_exists($field, $payload)) {
            return $currentId;
        }

        if (empty($payload[$field])) {
            return null;
        }

        $query = $class::query()->where('uuid', $payload[$field]);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return (int) $query->firstOrFail()->getKey();
    }

    private function fillNormApplicability(Model $model, User $user): void
    {
        $model->name = trim((string) $model->name);
        $model->normalized_name = mb_strtolower($model->name);
        $model->code = $model->code ?: 'custom';

        $duplicate = TimeNormApplicability::query()
            ->where('tenant_id', $user->tenantId())
            ->where('normalized_name', $model->normalized_name)
            ->when(
                $model->exists,
                fn ($query) => $query->where('id', '!=', $model->getKey()),
            )
            ->exists();

        if ($duplicate) {
            Input::fail('Такая применимость уже существует', 'name');
        }
    }

    private function defaultApplicability(int $tenantId): TimeNormApplicability
    {
        return $this->applicabilityByCode($tenantId, 'general', 'Общая');
    }

    private function applicabilityByCode(
        int $tenantId,
        string $code,
        string $name,
    ): TimeNormApplicability {
        $applicability = TimeNormApplicability::query()->firstOrNew(
            [
                'tenant_id'       => $tenantId,
                'normalized_name' => mb_strtolower($name),
            ],
        );
        $applicability->forceFill([
            'code'      => $code,
            'name'      => $name,
            'is_system' => true,
            'active'    => true,
        ]);

        if (!$applicability->exists || $applicability->isDirty()) {
            $applicability->save();
        }

        return $applicability;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fillReason(Model $model, array $payload, User $user, bool $create): void
    {
        if ($create && !isset($payload['code'])) {
            Input::fail('Укажите уникальный код причины');
        }

        if (isset($payload['parentId'])) {
            $parent = $payload['parentId'] === ''
                ? null
                : ServiceReason::query()
                    ->where('tenant_id', $user->tenantId())
                    ->whereNull('parent_id')
                    ->where('uuid', $payload['parentId'])
                    ->firstOrFail();

            $isSelf = $parent !== null && $parent->id === $model->id;
            $hasChildren = $model->exists
                && ServiceReason::query()->where('parent_id', $model->id)->exists();

            if ($isSelf || ($parent !== null && $hasChildren)) {
                Input::fail(
                    'Допускаются только причина и один уровень подвидов',
                );
            }

            $model->parent_id = $parent?->id;
        }

        $duplicateCode = ServiceReason::query()
            ->where('tenant_id', $user->tenantId())
            ->where('code', $model->code)
            ->when($model->exists, fn ($q) => $q->where('id', '!=', $model->id))
            ->exists();

        if ($duplicateCode) {
            Input::fail('Код причины уже используется');
        }
    }

    /* Сопутствующие данные. */

    /**
     * Нормы сохраняются в одной транзакции с работой. Ручное значение из заявки
     * сюда не попадает и остаётся снимком в request_works.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $deletedIds
     */
    private function syncWorkTypeNorms(
        User $user,
        Model $workType,
        array $items,
        array $deletedIds,
    ): void {
        foreach ($deletedIds as $deletedId) {
            $norm = TimeNorm::query()
                ->where('work_type_id', $workType->id)
                ->where('uuid', $deletedId)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $norm->attributesToArray();

            $norm->forceFill([
                'active'      => false,
                'archived_at' => now(),
            ])->save();

            $this->audit->record(
                $user->tenantId(),
                $user,
                CatalogKind::TimeNorms,
                $norm,
                'delete',
                $before,
                $norm->attributesToArray(),
            );
        }

        foreach ($items as $item) {
            $norm = !empty($item['id']) && Str::isUuid((string) $item['id'])
                ? TimeNorm::query()
                    ->where('work_type_id', $workType->id)
                    ->where('uuid', $item['id'])
                    ->lockForUpdate()
                    ->firstOrFail()
                : new TimeNorm(['work_type_id' => $workType->id]);
            $before = $norm->exists ? $norm->attributesToArray() : null;
            $payload = array_merge($item, ['workTypeId' => $workType->uuid]);

            if (($payload['hours'] ?? null) === '') {
                $payload['hours'] = null;
            }

            $norm->hours = $payload['hours'] ?? null;
            $norm->source = (string) ($payload['source'] ?? '');
            $this->fillTimeNorm($norm, $payload, $user);
            $norm->save();

            $this->audit->record(
                $user->tenantId(),
                $user,
                CatalogKind::TimeNorms,
                $norm,
                $before === null ? 'create' : 'update',
                $before,
                $norm->attributesToArray(),
            );
        }
    }

    private function syncRegulationItems(User $user, Model $regulation, array $items): void
    {
        MaintenanceRegulationItem::query()
            ->where('maintenance_regulation_id', $regulation->id)
            ->delete();

        foreach ($items as $item) {
            $workType = WorkType::query()
                ->where('tenant_id', $user->tenantId())
                ->where('uuid', $item['workTypeId'])
                ->firstOrFail();

            $timeNorm = empty($item['timeNormId'])
                ? null
                : TimeNorm::query()
                ->where('work_type_id', $workType->id)
                ->where('uuid', $item['timeNormId'])
                ->firstOrFail();

            MaintenanceRegulationItem::query()->create([
                'maintenance_regulation_id' => $regulation->id,
                'work_type_id'              => $workType->id,
                'time_norm_id'              => $timeNorm?->id,
                'quantity'                  => $item['qty'],
            ]);
        }
    }

    /** @return array<int, string> */
    private function timeNormRelations(): array
    {
        return [
            'workType',
            'applicability',
            'vehicleMake',
            'vehicleModel.make',
            'vehicle',
            'vehicleGeneration.model',
            'vehicleEngineVariant.model',
            'vehicleTransmissionType',
            'vehicleDriveType',
        ];
    }

    private function normDisplayCode(Model $norm): string
    {
        $parts = array_filter([
            $norm->vehicle?->vin !== null ? 'VIN:' . $norm->vehicle->vin : null,
            $norm->vehicleMake?->name,
            $norm->vehicleModel?->name,
            $norm->vehicleGeneration?->name,
            $norm->vehicleEngineVariant?->code,
            $norm->vehicleTransmissionType?->name,
            $norm->vehicleDriveType?->name,
            $norm->modification_code !== null
                ? 'MOD:' . $norm->modification_code
                : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $parts === [] ? 'Общая' : implode(' / ', $parts);
    }

    /**
     * Подзапрос ID типов работ тенанта.
     */
    private function workTypeIdsSubquery(int $tenantId): \Illuminate\Database\Query\Builder
    {
        return WorkType::query()
            ->where('tenant_id', $tenantId)
            ->select('id')
            ->getQuery();
    }

    private function presentRegulations($regulations): array
    {
        // Загружаем все items одним запросом
        $regulationIds = $regulations->pluck('id');

        $items = MaintenanceRegulationItem::query()
        ->whereIn('maintenance_regulation_id', $regulationIds)
            ->with(['workType', 'timeNorm'])
            ->get()
            ->groupBy('maintenance_regulation_id');

        $result = [];
        foreach ($regulations as $regulation) {
            $regulationItems = $items->get($regulation->id, collect());

            $presentedItems = [];
            foreach ($regulationItems as $item) {
                $presentedItems[] = [
                    'workTypeId' => $item->workType?->uuid ?? '',
                    'timeNormId' => $item->timeNorm?->uuid ?? '',
                    'qty'        => (float) $item->quantity,
                ];
            }

            $result[] = array_merge(
                [
                    'id'       => $regulation->uuid,
                    'uuid'     => $regulation->uuid,
                    'systemId' => $regulation->id,
                    'kind'     => CatalogKind::Regulations->value,
                ],
                [
                    'name'           => $regulation->name,
                    'vehicleModel'   => $regulation->vehicle_model,
                    'intervalKm'     => $regulation->interval_km,
                    'intervalMonths' => $regulation->interval_months,
                    'source'         => $regulation->source,
                    'active'         => $regulation->active,
                    'items'          => $presentedItems,
                ],
                [
                    'permissions' => [
                        'canEdit'       => true,
                        'canCreateOnly' => false,
                    ],
                ],
            );
        }
        return $result;
    }
}
