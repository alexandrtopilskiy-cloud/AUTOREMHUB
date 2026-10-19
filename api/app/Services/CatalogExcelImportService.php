<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Enums\PersonKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\TimeNormApplicability;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\WorkType;
use App\Support\DomainInput as Input;
use App\Support\RussianPhone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogExcelImportService
{
    private const MAX_ROWS = 100000;

    private const STATION_INTEGER_FIELDS = [
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
    ];

    private const STATION_BOOLEAN_FIELDS = [
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
    ];

    public function __construct(
        private readonly XlsxTableReader $reader,
        private readonly CatalogService $catalogs,
        private readonly VehicleCatalogService $vehicles,
        private readonly OrganizationPersonService $people,
        private readonly VehicleReferenceService $vehicleReference,
    ) {
    }

    /**
     * Проверяет весь файл и добавляет записи одной транзакцией.
     *
     * @return array{
     *     success: bool,
     *     catalog: string,
     *     rows: int,
     *     imported: int,
     *     conflicts: array<int, array<string, mixed>>
     * }
     */
    public function import(User $user, string $kind, string $path): array
    {
        $this->assertManager($user);
        $catalogKind = CatalogKind::tryFrom($kind);

        if ($catalogKind === null || !CatalogImportSchema::supports($catalogKind->value)) {
            Input::fail('Импорт для этого справочника не поддерживается');
        }

        $parsed = $this->readRows($catalogKind->value, $path);

        if ($parsed['conflicts'] !== []) {
            return $this->result(
                $catalogKind->value,
                count($parsed['rows']),
                0,
                $parsed['conflicts'],
            );
        }

        $conflicts = [];
        $operations = $this->operations($user, $catalogKind, $parsed['rows'], $conflicts);

        if ($conflicts !== []) {
            return $this->result(
                $catalogKind->value,
                count($parsed['rows']),
                0,
                $conflicts,
            );
        }

        DB::beginTransaction();

        try {
            $imported = 0;

            foreach ($operations as $operation) {
                try {
                    $this->assertNotDuplicate($user, $catalogKind, $operation['payload']);
                    $this->create($user, $catalogKind, $operation['payload']);
                    $imported++;
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $conflicts[] = $this->operationConflict(
                            $catalogKind->value,
                            $operation,
                            $field,
                            (string) ($messages[0] ?? 'Проверьте значение'),
                        );
                    }
                } catch (ApiException $exception) {
                    if ($exception->status === 403) {
                        throw $exception;
                    }

                    $fields = $exception->fields === []
                        ? ['' => $exception->getMessage()]
                        : $exception->fields;

                    foreach ($fields as $field => $message) {
                        $conflicts[] = $this->operationConflict(
                            $catalogKind->value,
                            $operation,
                            (string) $field,
                            (string) $message,
                        );
                    }
                } catch (ModelNotFoundException) {
                    $conflicts[] = $this->operationConflict(
                        $catalogKind->value,
                        $operation,
                        '',
                        'Связанная запись не найдена',
                    );
                } catch (QueryException) {
                    $conflicts[] = $this->operationConflict(
                        $catalogKind->value,
                        $operation,
                        '',
                        'Запись конфликтует с данными базы',
                    );

                    break;
                }
            }

            if ($conflicts !== []) {
                DB::rollBack();

                return $this->result(
                    $catalogKind->value,
                    count($parsed['rows']),
                    0,
                    $conflicts,
                );
            }

            DB::commit();

            return $this->result(
                $catalogKind->value,
                count($parsed['rows']),
                $imported,
                [],
            );
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     conflicts: array<int, array<string, mixed>>
     * }
     */
    private function readRows(string $kind, string $path): array
    {
        $headers = CatalogImportSchema::headers($kind);
        $expected = array_map(
            static fn (string $header): string => XlsxTableReader::normalizeHeader($header),
            $headers,
        );
        $headerRow = null;
        $columns = [];
        $conflicts = [];
        $rows = [];

        try {
            foreach ($this->reader->rows($path, [array_values($headers)]) as $rowNumber => $cells) {
                if ($headerRow === null) {
                    $normalized = array_map(
                        static fn (string $value): string => XlsxTableReader::normalizeHeader($value),
                        $cells,
                    );

                    if (array_intersect($normalized, $expected) === []) {
                        continue;
                    }

                    $headerRow = (int) $rowNumber;
                    $recognized = [];

                    foreach ($cells as $index => $value) {
                        $normalizedHeader = XlsxTableReader::normalizeHeader($value);

                        if ($normalizedHeader === '') {
                            continue;
                        }

                        $field = array_search($normalizedHeader, $expected, true);

                        if ($field === false) {
                            $conflicts[] = $this->conflict(
                                $kind,
                                $headerRow,
                                $this->cell((int) $index, $headerRow),
                                'Заголовок',
                                $value,
                                'Неизвестный столбец. Используйте шаблон этого справочника',
                            );

                            continue;
                        }

                        if (isset($recognized[$field])) {
                            $conflicts[] = $this->conflict(
                                $kind,
                                $headerRow,
                                $this->cell((int) $index, $headerRow),
                                $headers[$field],
                                $value,
                                'Столбец указан в файле повторно',
                            );

                            continue;
                        }

                        $recognized[$field] = true;
                        $columns[$field] = (int) $index;
                    }

                    foreach ($headers as $field => $header) {
                        if (isset($columns[$field])) {
                            continue;
                        }

                        $expectedIndex = (int) array_search(
                            $field,
                            array_keys($headers),
                            true,
                        );
                        $conflicts[] = $this->conflict(
                            $kind,
                            $headerRow,
                            $this->cell($expectedIndex, $headerRow),
                            $header,
                            '',
                            'Обязательный столбец отсутствует',
                        );
                    }

                    if ($conflicts !== []) {
                        return ['rows' => [], 'conflicts' => $conflicts];
                    }

                    continue;
                }

                $values = [];
                $hasValue = false;

                foreach ($columns as $field => $index) {
                    $value = trim((string) ($cells[$index] ?? ''));
                    $values[$field] = $value;
                    $hasValue = $hasValue || $value !== '';
                }

                if (!$hasValue) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    $conflicts[] = $this->conflict(
                        $kind,
                        (int) $rowNumber,
                        $this->cell(0, (int) $rowNumber),
                        'Строка',
                        '',
                        'В одном файле допускается не более 100000 строк данных',
                    );

                    break;
                }

                $rows[] = [
                    'number'  => (int) $rowNumber,
                    'values'  => $values,
                    'columns' => $columns,
                    'headers' => $headers,
                ];
            }
        } catch (ApiException $exception) {
            return [
                'rows' => [],
                'conflicts' => [$this->conflict(
                    $kind,
                    1,
                    'A1',
                    'Файл XLSX',
                    '',
                    $exception->getMessage(),
                )],
            ];
        }

        if ($headerRow === null) {
            $conflicts[] = $this->conflict(
                $kind,
                1,
                'A1',
                'Заголовки',
                '',
                'Не найдена строка заголовков шаблона',
            );
        }

        if ($headerRow !== null && $rows === [] && $conflicts === []) {
            $conflicts[] = $this->conflict(
                $kind,
                $headerRow + 1,
                $this->cell(0, $headerRow + 1),
                'Данные',
                '',
                'В файле нет строк для импорта',
            );
        }

        return ['rows' => $rows, 'conflicts' => $conflicts];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $conflicts
     *
     * @return array<int, array<string, mixed>>
     */
    private function operations(
        User $user,
        CatalogKind $kind,
        array $rows,
        array &$conflicts,
    ): array {
        $operations = [];

        foreach ($rows as $row) {
            $before = count($conflicts);
            $payload = $this->payload($user, $kind, $row, $conflicts);

            if (count($conflicts) > $before) {
                continue;
            }

            $operation = [
                'payload' => $payload,
                'row'     => $row['number'],
                'fields'  => $this->fieldLocations($row),
            ];

            if ($kind !== CatalogKind::WorkTypes) {
                $operations[] = $operation;

                continue;
            }

            $key = VehicleReferenceService::normalizeName((string) $payload['name']);

            if (!isset($operations[$key])) {
                $operations[$key] = $operation;

                continue;
            }

            $existing = &$operations[$key];

            foreach (['group', 'isMaintenance', 'active'] as $field) {
                if ($existing['payload'][$field] === $payload[$field]) {
                    continue;
                }

                $location = $operation['fields'][$field];
                $conflicts[] = $this->conflict(
                    $kind->value,
                    $location['row'],
                    $location['cell'],
                    $location['column'],
                    $location['value'],
                    'Для повторяющейся работы основные поля должны совпадать',
                );
            }

            $existing['payload']['timeNorms'][] = $payload['timeNorms'][0];

            foreach ([
                'applicabilityId' => 'applicability',
                'vehicleMakeId'   => 'vehicleMake',
                'vehicleModelId'  => 'vehicleModel',
                'hours'           => 'hours',
                'source'          => 'source',
            ] as $apiField => $excelField) {
                $existing['fields']['timeNorms.'
                    . (count($existing['payload']['timeNorms']) - 1)
                    . '.' . $apiField] = $operation['fields'][$excelField];
            }

            unset($existing);
        }

        return array_values($operations);
    }

    /**
     * @param array<string, mixed>              $row
     * @param array<int, array<string, mixed>>  $conflicts
     *
     * @return array<string, mixed>
     */
    private function payload(
        User $user,
        CatalogKind $kind,
        array $row,
        array &$conflicts,
    ): array {
        $values = $row['values'];

        return match ($kind) {
            CatalogKind::Vehicles => $this->vehiclePayload($user, $row, $conflicts),
            CatalogKind::VehicleModels => [
                'make'          => $values['make'],
                'model'         => $values['model'],
                'modelYearFrom' => $this->integer($row, 'modelYearFrom'),
                'modelYearTo'   => $this->integer($row, 'modelYearTo'),
                'engineIndex'   => $values['engineIndex'],
                'bodyCode'      => $values['bodyCode'],
                'horsepower'    => $this->decimal($row, 'horsepower'),
                'engineVolumeLiters' => $this->decimal($row, 'engineVolumeLiters'),
                'transmission'  => $this->nullable($values['transmission']),
                'driveType'     => $this->nullable($values['driveType']),
                'engineType'    => $this->nullable($values['engineType']),
            ],
            CatalogKind::Drivers,
            CatalogKind::ContactPersons => $this->personPayload(
                $user,
                $kind,
                $row,
                $conflicts,
            ),
            CatalogKind::WorkTypes => $this->workPayload($user, $row, $conflicts),
            CatalogKind::NormApplicabilities => [
                'name'   => $values['name'],
                'active' => $this->boolean($row, 'active', true),
            ],
            CatalogKind::Parts => $this->partPayload($user, $row, $conflicts),
            CatalogKind::Customers,
            CatalogKind::Managers => $this->organizationPayload($row),
            CatalogKind::Stations => $this->stationPayload($row, $conflicts),
            CatalogKind::Users => $this->userPayload($user, $row, $conflicts),
            default => throw new ApiException(
                422,
                'VALIDATION_ERROR',
                'Импорт для этого справочника не поддерживается',
            ),
        };
    }

    /** @return array<string, mixed> */
    private function vehiclePayload(User $user, array $row, array &$conflicts): array
    {
        $values = $row['values'];
        $organization = $this->organization(
            $user,
            CatalogKind::Vehicles->value,
            $row,
            'organization',
            OrganizationKind::Customer,
            $conflicts,
        );
        $model = $this->vehicleModel($row, $conflicts);
        $contacts = $organization === null
            ? []
            : $this->personIds(
                $row,
                'contactPhones',
                $organization,
                PersonKind::Contact,
                $conflicts,
            );
        $drivers = $organization === null
            ? []
            : $this->personIds(
                $row,
                'driverPhones',
                $organization,
                PersonKind::Driver,
                $conflicts,
            );

        return [
            'organizationId'        => $organization?->uuid ?? '',
            'make'                  => $model?->make->name ?? $values['make'],
            'model'                 => $model?->name ?? $values['model'],
            'vehicleModelId'        => $model?->uuid ?? '',
            'vin'                   => mb_strtoupper($values['vin']),
            'plate'                 => mb_strtoupper($values['plate']),
            'year'                  => $this->integer($row, 'year'),
            'baselineServiceMileage' => $this->integer(
                $row,
                'baselineServiceMileage',
            ),
            'baselineServiceAt'     => $this->date($row, 'baselineServiceAt'),
            'laborHourRate'         => $this->decimal($row, 'laborHourRate'),
            'laborHourRateOfficial' => $this->decimal($row, 'laborHourRateOfficial'),
            'laborHourRateOther' => $this->decimal($row, 'laborHourRateOther'),
            'laborRateCoefficient'  => $this->decimal(
                $row,
                'laborRateCoefficient',
                1.0,
            ),
            'contactPersonIds'      => $contacts,
            'driverPersonIds'       => $drivers,
        ];
    }

    /** @return array<string, mixed> */
    private function personPayload(
        User $user,
        CatalogKind $kind,
        array $row,
        array &$conflicts,
    ): array {
        $values = $row['values'];
        $organization = $this->organization(
            $user,
            $kind->value,
            $row,
            'organization',
            OrganizationKind::Customer,
            $conflicts,
        );

        return [
            'kind'           => $kind === CatalogKind::Drivers
                ? PersonKind::Driver->value
                : PersonKind::Contact->value,
            'organizationId' => $organization?->uuid ?? '',
            'lastName'       => $values['lastName'],
            'firstName'      => $values['firstName'],
            'middleName'     => $values['middleName'],
            'phone'          => $values['phone'],
        ];
    }

    /** @return array<string, mixed> */
    private function workPayload(User $user, array $row, array &$conflicts): array
    {
        $values = $row['values'];
        $applicabilityName = $values['applicability'] !== ''
            ? $values['applicability']
            : 'Общая';
        $applicability = TimeNormApplicability::query()
            ->where('tenant_id', $user->tenantId())
            ->where(
                'normalized_name',
                VehicleReferenceService::normalizeName($applicabilityName),
            )
            ->where('active', true)
            ->first();

        if ($applicability === null) {
            $this->addRowConflict(
                $conflicts,
                CatalogKind::WorkTypes->value,
                $row,
                'applicability',
                'Применимость не найдена в справочнике',
            );
        }

        $make = null;
        $model = null;

        if ($applicability?->code === 'vehicle') {
            $makeName = $values['vehicleMake'];
            $modelName = $values['vehicleModel'];

            if ($makeName === '' && $modelName === '') {
                $this->addRowConflict(
                    $conflicts,
                    CatalogKind::WorkTypes->value,
                    $row,
                    'vehicleMake',
                    'Для применимости «По авто» укажите марку и/или модель',
                );
            } else {
                $make = $makeName === '' ? null : VehicleMake::query()
                    ->where('normalized_name', VehicleReferenceService::normalizeName($makeName))
                    ->where('active', true)
                    ->first();

                if ($makeName !== '' && $make === null) {
                    $this->addRowConflict(
                        $conflicts,
                        CatalogKind::WorkTypes->value,
                        $row,
                        'vehicleMake',
                        'Марка не найдена во внутреннем справочнике ТС',
                    );
                }

                if ($modelName !== '') {
                    $modelQuery = VehicleModel::query()
                        ->with('make')
                        ->where('normalized_name', VehicleReferenceService::normalizeName($modelName))
                        ->where('active', true);

                    if ($make !== null) {
                        $modelQuery->where('vehicle_make_id', $make->id);
                    }

                    $models = $modelQuery->limit(2)->get();

                    if ($models->count() !== 1) {
                        $this->addRowConflict(
                            $conflicts,
                            CatalogKind::WorkTypes->value,
                            $row,
                            'vehicleModel',
                            $models->isEmpty()
                                ? 'Модель не найдена во внутреннем справочнике ТС'
                                : 'Найдены модели разных марок. Уточните марку ТС',
                        );
                    } else {
                        $model = $models->first();
                        $make ??= $model->make;
                    }
                }
            }
        } elseif ($values['vehicleMake'] !== '' || $values['vehicleModel'] !== '') {
            $this->addRowConflict(
                $conflicts,
                CatalogKind::WorkTypes->value,
                $row,
                $values['vehicleMake'] !== '' ? 'vehicleMake' : 'vehicleModel',
                'Марка и модель допустимы только для применимости «По авто»',
            );
        }

        return [
            'name'          => $values['name'],
            'group'         => $values['group'],
            'isMaintenance' => $this->boolean($row, 'isMaintenance', false),
            'active'        => $this->boolean($row, 'active', true),
            'timeNorms'     => [[
                'applicabilityId' => $applicability?->uuid ?? '',
                'vehicleMakeId'   => $make?->uuid ?? '',
                'vehicleModelId'  => $model?->uuid ?? '',
                'legacyVehicleModel' => '',
                'hours'           => $this->decimal($row, 'hours'),
                'source'          => $values['source'],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function organizationPayload(array $row): array
    {
        $payload = $row['values'];
        $payload['vatRate'] = $this->decimal($row, 'vatRate');

        return $payload;
    }

    /** @return array<string, mixed> */
    private function stationPayload(array $row, array &$conflicts): array
    {
        $payload = $this->organizationPayload($row);
        $payload['workingDays'] = $this->workingDays($row, $conflicts);
        $payload['active'] = $this->boolean($row, 'active', true);

        foreach (self::STATION_INTEGER_FIELDS as $field) {
            $payload[$field] = $this->integer($row, $field, 0);
        }

        foreach (self::STATION_BOOLEAN_FIELDS as $field) {
            $payload[$field] = $this->boolean($row, $field, false);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function partPayload(User $user, array $row, array &$conflicts): array
    {
        $values = $row['values'];

        if (!in_array($values['unit'], config('sto.part_units'), true)) {
            $this->addRowConflict(
                $conflicts,
                CatalogKind::Parts->value,
                $row,
                'unit',
                'Единица измерения отсутствует в закрытом перечне',
            );
        }

        $manufacturer = \App\Models\PartManufacturer::query()->where('tenant_id', $user->tenantId())->where('status', 'approved')->where('active', true)->where('normalized_name', mb_strtolower(trim($values['manufacturer'])))->first();
        $supplier = \App\Models\Supplier::query()->where('tenant_id', $user->tenantId())->where('active', true)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($values['supplier']))])->get();
        if (!$manufacturer) {
            $this->addRowConflict($conflicts, 'parts', $row, 'manufacturer', 'Производитель не найден в согласованном справочнике');
        }
        if ($supplier->count() !== 1) {
            $this->addRowConflict($conflicts, 'parts', $row, 'supplier', 'Поставщик не найден или название неоднозначно');
        }
        return [
            'manufacturerId' => $manufacturer?->uuid ?? '',
            'supplierId' => $supplier->count() === 1 ? $supplier->first()->uuid : '',
            'purchasePrice' => $this->decimal($row, 'purchasePrice'),
            'salePrice' => $this->decimal($row, 'salePrice'),
            'name'         => $values['name'],
            'article'      => $values['article'],
            'manufacturer' => $values['manufacturer'],
            'unit'         => $values['unit'],
            'active'       => $this->boolean($row, 'active', true),
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user, array $row, array &$conflicts): array
    {
        $values = $row['values'];
        $organization = $this->organization(
            $user,
            CatalogKind::Users->value,
            $row,
            'organization',
            null,
            $conflicts,
        );
        $rights = $this->list($values['accessRights']);

        if ($rights === [] && $organization !== null) {
            $rights = UserDirectoryService::defaultRights($organization->kind);
        }

        return [
            'lastName'        => $values['lastName'],
            'firstName'       => $values['firstName'],
            'middleName'      => $values['middleName'],
            'email'           => $values['email'],
            'login'           => $values['login'],
            'password'        => $values['password'],
            'phone'           => $values['phone'],
            'organizationId'  => $organization?->uuid ?? '',
            'registeredAt'    => $this->date($row, 'registeredAt'),
            'accessRevokedAt' => $this->date($row, 'accessRevokedAt'),
            'active'          => $this->boolean($row, 'active', true),
            'accessRights'    => $rights,
        ];
    }

    private function organization(
        User $user,
        string $catalog,
        array $row,
        string $field,
        ?OrganizationKind $kind,
        array &$conflicts,
    ): ?Organization {
        $name = VehicleReferenceService::normalizeName($row['values'][$field]);
        $query = Organization::query()
            ->where('tenant_id', $user->tenantId())
            ->whereRaw('LOWER(TRIM(name)) = ?', [$name]);

        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }

        $organizations = $query->limit(2)->get();

        if ($organizations->count() !== 1) {
            $this->addRowConflict(
                $conflicts,
                $catalog,
                $row,
                $field,
                $organizations->isEmpty()
                    ? 'Организация не найдена'
                    : 'Найдено несколько организаций с таким наименованием',
            );

            return null;
        }

        return $organizations->first();
    }

    private function vehicleModel(array $row, array &$conflicts): ?VehicleModel
    {
        $values = $row['values'];
        $query = VehicleModel::query()
            ->with('make')
            ->where('normalized_name', VehicleReferenceService::normalizeName($values['model']))
            ->where('active', true)
            ->whereHas(
                'make',
                fn ($make) => $make
                    ->where('normalized_name', VehicleReferenceService::normalizeName($values['make']))
                    ->where('active', true),
            );

        if ($values['bodyCode'] !== '') {
            $query->whereRaw('LOWER(TRIM(body_code)) = ?', [
                mb_strtolower(trim($values['bodyCode'])),
            ]);
        }

        $year = $this->integer($row, 'year');

        if (is_int($year)) {
            $query->where(function ($builder) use ($year): void {
                $builder->whereNull('model_year_from')
                    ->orWhere('model_year_from', '<=', $year);
            })->where(function ($builder) use ($year): void {
                $builder->whereNull('model_year_to')
                    ->orWhere('model_year_to', '>=', $year);
            });
        }

        $technical = ['modelYearFrom' => 'model_year_from', 'modelYearTo' => 'model_year_to',
            'engineType' => 'engine_type', 'engineIndex' => 'engine_index',
            'horsepower' => 'horsepower', 'engineVolumeLiters' => 'engine_volume_liters',
            'transmission' => 'transmission', 'driveType' => 'drive_type'];
        foreach ($technical as $field => $column) {
            if (($values[$field] ?? '') !== '') {
                $query->where($column, $values[$field]);
            }
        }
        $models = $query->oldest('id')->limit(2)->get();
        $bodyVariants = $models
            ->map(static fn (VehicleModel $model): string => mb_strtolower(trim($model->body_code)))
            ->unique();

        if ($models->isEmpty() || $models->count() > 1) {
            $this->addRowConflict(
                $conflicts,
                CatalogKind::Vehicles->value,
                $row,
                $models->isEmpty() ? 'model' : 'bodyCode',
                $models->isEmpty()
                    ? 'Марка и модель не найдены во внутреннем справочнике ТС'
                    : 'Найдено несколько вариантов. Укажите поколение, двигатель, объём, трансмиссию и привод',
            );

            return null;
        }

        return $models->first();
    }

    /** @return array<int, string> */
    private function personIds(
        array $row,
        string $field,
        Organization $organization,
        PersonKind $kind,
        array &$conflicts,
    ): array {
        $ids = [];

        foreach ($this->list($row['values'][$field]) as $phoneValue) {
            $phone = RussianPhone::normalize($phoneValue);

            if ($phone === null) {
                $this->addRowConflict(
                    $conflicts,
                    CatalogKind::Vehicles->value,
                    $row,
                    $field,
                    'Телефон должен быть в формате +7 (ххх) ххх хх хх',
                    $phoneValue,
                );

                continue;
            }

            $people = OrganizationPerson::query()
                ->where('organization_id', $organization->id)
                ->where('kind', $kind->value)
                ->where('phone', $phone)
                ->where('active', true)
                ->limit(2)
                ->get();

            if ($people->count() !== 1) {
                $this->addRowConflict(
                    $conflicts,
                    CatalogKind::Vehicles->value,
                    $row,
                    $field,
                    $people->isEmpty()
                        ? 'Телефон не найден в справочнике этой организации'
                        : 'По телефону найдено несколько записей',
                    $phoneValue,
                );

                continue;
            }

            $ids[] = $people->first()->uuid;
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, int> */
    private function workingDays(array $row, array &$conflicts): array
    {
        $aliases = [
            '1' => 1, 'пн' => 1, 'понедельник' => 1,
            '2' => 2, 'вт' => 2, 'вторник' => 2,
            '3' => 3, 'ср' => 3, 'среда' => 3,
            '4' => 4, 'чт' => 4, 'четверг' => 4,
            '5' => 5, 'пт' => 5, 'пятница' => 5,
            '6' => 6, 'сб' => 6, 'суббота' => 6,
            '7' => 7, 'вс' => 7, 'воскресенье' => 7,
        ];
        $days = [];

        foreach ($this->list($row['values']['workingDays']) as $value) {
            $key = mb_strtolower(rtrim(trim($value), '.'));

            if (!isset($aliases[$key])) {
                $this->addRowConflict(
                    $conflicts,
                    CatalogKind::Stations->value,
                    $row,
                    'workingDays',
                    'Используйте Пн.;Вт.;Ср.;Чт.;Пт.;Сб.;Вс. или числа 1–7',
                    $value,
                );

                continue;
            }

            $days[] = $aliases[$key];
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /** @param array<string, mixed> $payload */
    private function assertNotDuplicate(
        User $user,
        CatalogKind $kind,
        array $payload,
    ): void {
        $tenantId = $user->tenantId();
        $field = match ($kind) {
            CatalogKind::Vehicles => Vehicle::query()
                ->where('vin', mb_strtoupper((string) $payload['vin']))
                ->exists() ? 'vin' : null,
            CatalogKind::VehicleModels => $this->vehicleModelDuplicate($payload)
                ? 'model'
                : null,
            CatalogKind::Drivers,
            CatalogKind::ContactPersons => OrganizationPerson::query()
                ->where('organization_id', $this->organizationId($payload['organizationId']))
                ->where('kind', $payload['kind'])
                ->where('phone', RussianPhone::normalize((string) $payload['phone']))
                ->where('active', true)
                ->exists() ? 'phone' : null,
            CatalogKind::WorkTypes => WorkType::query()
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [
                    VehicleReferenceService::normalizeName((string) $payload['name']),
                ])
                ->exists() ? 'name' : null,
            CatalogKind::NormApplicabilities => TimeNormApplicability::query()
                ->where('tenant_id', $tenantId)
                ->where(
                    'normalized_name',
                    VehicleReferenceService::normalizeName((string) $payload['name']),
                )
                ->exists() ? 'name' : null,
            CatalogKind::Parts => $this->partDuplicate($tenantId, $payload)
                ? ($payload['article'] !== '' ? 'article' : 'name')
                : null,
            CatalogKind::Customers,
            CatalogKind::Stations,
            CatalogKind::Managers => $this->organizationDuplicate(
                $tenantId,
                $kind,
                $payload,
            ) ? 'name' : null,
            CatalogKind::Users => User::query()
                ->where(function ($query) use ($payload): void {
                    $query->where('login', $payload['login'])
                        ->orWhereRaw('LOWER(email) = ?', [
                            mb_strtolower((string) $payload['email']),
                        ]);
                })
                ->exists() ? 'login' : null,
            default => null,
        };

        if ($field !== null) {
            Input::fail('Такая запись уже существует в справочнике', $field);
        }
    }

    /** @param array<string, mixed> $payload */
    private function create(User $user, CatalogKind $kind, array $payload): void
    {
        match ($kind) {
            CatalogKind::Vehicles => $this->vehicles->create($user, $payload),
            CatalogKind::VehicleModels => $this->vehicleReference->create($user, $payload),
            CatalogKind::Drivers,
            CatalogKind::ContactPersons => $this->people->create($user, $payload),
            default => $this->catalogs->create($user, $kind, $payload),
        };
    }

    /** @param array<string, mixed> $payload */
    private function vehicleModelDuplicate(array $payload): bool
    {
        $make = VehicleMake::query()
            ->where('normalized_name', VehicleReferenceService::normalizeName((string) $payload['make']))
            ->first();

        if ($make === null) {
            return false;
        }

        return VehicleModel::query()
            ->where('vehicle_make_id', $make->id)
            ->where(
                'normalized_name',
                VehicleReferenceService::normalizeName((string) $payload['model']),
            )
            ->where('model_year_from', $payload['modelYearFrom'])
            ->where('model_year_to', $payload['modelYearTo'])
            ->where('engine_index', trim((string) $payload['engineIndex']))
            ->where('body_code', trim((string) $payload['bodyCode']))
            ->where('horsepower', $payload['horsepower'])
            ->where('engine_volume_liters', $payload['engineVolumeLiters'] ?? null)
            ->where('transmission', $payload['transmission'])
            ->where('drive_type', $payload['driveType'])
            ->where('engine_type', $payload['engineType'])
            ->exists();
    }

    /** @param array<string, mixed> $payload */
    private function partDuplicate(int $tenantId, array $payload): bool
    {
        return CatalogPart::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(TRIM(manufacturer)) = ?', [
                mb_strtolower(trim((string) $payload['manufacturer'])),
            ])
            ->whereRaw(
                'LOWER(TRIM(' . ($payload['article'] !== '' ? 'article' : 'name') . ')) = ?',
                [mb_strtolower(trim((string) (
                    $payload['article'] !== '' ? $payload['article'] : $payload['name']
                )))],
            )
            ->exists();
    }

    /** @param array<string, mixed> $payload */
    private function organizationDuplicate(
        int $tenantId,
        CatalogKind $catalog,
        array $payload,
    ): bool {
        $kind = match ($catalog) {
            CatalogKind::Customers => OrganizationKind::Customer->value,
            CatalogKind::Stations => OrganizationKind::Station->value,
            default => OrganizationKind::Manager->value,
        };
        $query = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', $kind)
            ->where(function ($builder) use ($payload): void {
                $builder->whereRaw('LOWER(TRIM(name)) = ?', [
                    VehicleReferenceService::normalizeName((string) $payload['name']),
                ]);

                if (($payload['inn'] ?? '') !== '') {
                    $builder->orWhere('inn', $payload['inn']);
                }
            });

        return $query->exists();
    }

    private function organizationId(string $uuid): int
    {
        return (int) Organization::query()->where('uuid', $uuid)->value('id');
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldLocations(array $row): array
    {
        $locations = [];

        foreach ($row['values'] as $field => $value) {
            $index = $row['columns'][$field];
            $locations[$field] = [
                'row'    => $row['number'],
                'cell'   => $this->cell($index, $row['number']),
                'column' => $row['headers'][$field],
                'value'  => $value,
            ];
        }

        return $locations;
    }

    /** @return array<string, mixed> */
    private function operationConflict(
        string $kind,
        array $operation,
        string $field,
        string $message,
    ): array {
        $originalField = $field;
        $field = preg_replace('/^timeNorms\.\d+\./', '', $field) ?? $field;
        $field = explode('.', $field)[0];
        $field = [
            'applicabilityId' => 'applicability',
            'vehicleMakeId'   => $kind === CatalogKind::WorkTypes->value
                ? 'vehicleMake'
                : 'make',
            'vehicleModelId'  => $kind === CatalogKind::WorkTypes->value
                ? 'vehicleModel'
                : 'model',
            'legacyVehicleModel' => 'vehicleModel',
            'organizationId'  => 'organization',
            'contactPersonIds' => 'contactPhones',
            'driverPersonIds' => 'driverPhones',
        ][$field] ?? $field;
        $location = $operation['fields'][$originalField]
            ?? $operation['fields'][$field]
            ?? reset($operation['fields']);

        return $this->conflict(
            $kind,
            (int) ($location['row'] ?? $operation['row']),
            (string) ($location['cell'] ?? 'A' . $operation['row']),
            (string) ($location['column'] ?? 'Запись'),
            (string) ($location['value'] ?? ''),
            $message,
        );
    }

    private function addRowConflict(
        array &$conflicts,
        string $kind,
        array $row,
        string $field,
        string $message,
        ?string $value = null,
    ): void {
        $index = $row['columns'][$field];
        $conflicts[] = $this->conflict(
            $kind,
            $row['number'],
            $this->cell($index, $row['number']),
            $row['headers'][$field],
            $value ?? $row['values'][$field],
            $message,
        );
    }

    /** @return array<string, mixed> */
    private function conflict(
        string $kind,
        int $row,
        string $cell,
        string $column,
        string $value,
        string $message,
    ): array {
        return [
            'catalog' => $kind,
            'row'     => $row,
            'cell'    => $cell,
            'column'  => $column,
            'value'   => $value,
            'message' => $message,
        ];
    }

    private function boolean(array $row, string $field, bool $default): mixed
    {
        $value = mb_strtolower(trim((string) $row['values'][$field]));

        if ($value === '') {
            return $default;
        }

        return match ($value) {
            '1', 'да', 'true', 'yes', 'активен', 'активна' => true,
            '0', 'нет', 'false', 'no', 'неактивен', 'неактивна' => false,
            default => $row['values'][$field],
        };
    }

    private function integer(array $row, string $field, ?int $default = null): mixed
    {
        $value = trim((string) $row['values'][$field]);

        if ($value === '') {
            return $default;
        }

        return preg_match('/^-?[0-9]+$/', $value) ? (int) $value : $value;
    }

    private function decimal(array $row, string $field, ?float $default = null): mixed
    {
        $value = str_replace(',', '.', trim((string) $row['values'][$field]));

        if ($value === '') {
            return $default;
        }

        return is_numeric($value) ? (float) $value : $row['values'][$field];
    }

    private function date(array $row, string $field): ?string
    {
        $value = trim((string) $row['values'][$field]);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30)
                ->addDays((int) floor((float) $value))
                ->format('Y-m-d');
        }

        foreach (['!d.m.Y', '!Y-m-d', '!d.m.Y H:i', '!Y-m-d H:i'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false) {
                $inputFormat = ltrim($format, '!');

                if ($date->format($inputFormat) !== $value) {
                    continue;
                }

                return $date->format(
                    str_contains($format, 'H:i')
                        ? 'Y-m-d H:i:s'
                        : 'Y-m-d',
                );
            }
        }

        return $value;
    }

    /** @return array<int, string> */
    private function list(string $value): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[;\n]+/u', $value) ?: [],
        ), static fn (string $item): bool => $item !== ''));
    }

    private function nullable(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    private function cell(int $column, int $row): string
    {
        $name = '';
        $number = $column + 1;

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name . $row;
    }

    /** @return array<string, mixed> */
    private function result(
        string $kind,
        int $rows,
        int $imported,
        array $conflicts,
    ): array {
        return [
            'success'   => $conflicts === [],
            'catalog'   => $kind,
            'rows'      => $rows,
            'imported'  => $imported,
            'conflicts' => $conflicts,
        ];
    }

    private function assertManager(User $user): void
    {
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Импорт справочников доступен только Управляющей компании',
            );
        }
    }
}
