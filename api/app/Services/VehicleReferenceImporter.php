<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VehicleReferenceImporter
{
    private int $skippedDuplicates = 0;

    private const MAX_ROWS = 100_000;

    private const HEADERS = [
        'make'         => ['марка'],
        'model'        => ['модель'],
        'yearFrom'     => ['модельный год от'],
        'yearTo'       => ['модельный год до'],
        'engineIndex'  => ['индекс двигателя'],
        'bodyCode'     => ['номер кузова (кузов)', 'код кузова', 'поколение'],
        'horsepower'   => ['мощность, л.с.', 'мощность л.с.'],
        'engineVolume' => ['объем двигателя, л.', 'объём двигателя, л.'],
        'transmission' => ['вид трансмиссии'],
        'driveType'    => ['тип привода'],
        'engineType'   => ['тип двигателя'],
    ];

    public function __construct(
        private readonly XlsxTableReader $reader,
    ) {
    }

    /**
     * @return array{makes: int, created: int, existing: int, deactivated: int, total: int, dryRun: bool, skippedDuplicates: int}
     */
    public function import(
        string $path,
        bool $dryRun = false,
        bool $replace = false,
        bool $skipExactDuplicates = false,
    ): array {
        $this->skippedDuplicates = 0;
        $rows = $this->normalizeRows($this->reader->rows(
            $path,
            [self::HEADERS['make'], self::HEADERS['model']],
        ), $skipExactDuplicates);
        $fingerprints = array_column($rows, 'fingerprint');
        $existing = 0;

        foreach (array_chunk($fingerprints, 1000) as $chunk) {
            $existing += VehicleModel::query()
                ->whereIn('fingerprint', $chunk)
                ->count();
        }

        if ($dryRun) {
            return [
                'makes'   => count(array_unique(array_column($rows, 'makeKey'))),
                'created' => count($rows) - $existing,
                'existing' => $existing,
                'deactivated' => 0,
                'total'   => count($rows),
                'dryRun'  => true,
                'skippedDuplicates' => $this->skippedDuplicates,
            ];
        }

        return DB::transaction(function () use ($rows, $existing, $replace): array {
            $now = now();
            $makes = [];

            if ($replace) {
                VehicleModel::query()->update(['active' => false]);
                VehicleMake::query()->update(['active' => false]);
            }

            foreach ($rows as $row) {
                $makes[$row['makeKey']] = $row['make'];
            }

            VehicleMake::query()->upsert(
                collect($makes)->map(
                    fn (string $name, string $key): array => [
                        'uuid'            => (string) Str::uuid(),
                        'name'            => $name,
                        'normalized_name' => $key,
                        'active'          => true,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ],
                )->values()->all(),
                ['normalized_name'],
                ['name', 'active', 'updated_at'],
            );

            $makeIds = VehicleMake::query()
                ->whereIn('normalized_name', array_keys($makes))
                ->pluck('id', 'normalized_name');
            $modelRows = [];

            foreach ($rows as $row) {
                $modelRows[] = [
                    'uuid'             => (string) Str::uuid(),
                    'vehicle_make_id'  => $makeIds[$row['makeKey']],
                    'name'             => $row['model'],
                    'normalized_name'  => $row['modelKey'],
                    'model_year_from'  => $row['modelYearFrom'],
                    'model_year_to'    => $row['modelYearTo'],
                    'engine_index'     => $row['engineIndex'],
                    'body_code'        => $row['bodyCode'],
                    'horsepower'       => $row['horsepower'],
                    'engine_volume_liters' => $row['engineVolumeLiters'],
                    'transmission'     => $row['transmission'],
                    'source_transmission' => $row['sourceTransmission'],
                    'drive_type'       => $row['driveType'],
                    'source_drive_type' => $row['sourceDriveType'],
                    'engine_type'      => $row['engineType'],
                    'source_engine_type' => $row['sourceEngineType'],
                    'fingerprint'      => $row['fingerprint'],
                    'active'           => true,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }

            foreach (array_chunk($modelRows, 500) as $chunk) {
                VehicleModel::query()->upsert(
                    $chunk,
                    ['fingerprint'],
                    [
                        'vehicle_make_id',
                        'name',
                        'normalized_name',
                        'model_year_from',
                        'model_year_to',
                        'engine_index',
                        'body_code',
                        'horsepower',
                        'engine_volume_liters',
                        'transmission',
                        'source_transmission',
                        'drive_type',
                        'source_drive_type',
                        'engine_type',
                        'source_engine_type',
                        'active',
                        'updated_at',
                    ],
                );
            }

            $this->syncTypedApplicability();

            return [
                'makes'    => count($makes),
                'created'  => count($rows) - $existing,
                'existing' => $existing,
                'deactivated' => $replace
                    ? VehicleModel::query()->where('active', false)->count()
                    : 0,
                'total'    => count($rows),
                'dryRun'   => false,
                'skippedDuplicates' => $this->skippedDuplicates,
            ];
        });
    }

    private function syncTypedApplicability(): void
    {
        DB::statement(
            'UPDATE vehicle_generations AS generation SET '
            . 'active = model.active, updated_at = CURRENT_TIMESTAMP '
            . 'FROM vehicle_models AS model '
            . 'WHERE generation.vehicle_model_id = model.id'
        );
        DB::statement(
            'UPDATE vehicle_engine_variants AS engine SET '
            . 'active = model.active, updated_at = CURRENT_TIMESTAMP '
            . 'FROM vehicle_models AS model '
            . 'WHERE engine.vehicle_model_id = model.id'
        );
        DB::statement(
            "INSERT INTO vehicle_generations "
            . "(uuid, vehicle_model_id, name, normalized_name, year_from, "
            . "year_to, active, created_at, updated_at) "
            . "SELECT md5('vehicle-generation:' || model.id::text)::uuid, "
            . "model.id, btrim(model.body_code), "
            . "lower(btrim(regexp_replace(model.body_code, '\\s+', ' ', 'g'))), "
            . "model.model_year_from, model.model_year_to, model.active, "
            . "CURRENT_TIMESTAMP, CURRENT_TIMESTAMP "
            . "FROM vehicle_models AS model "
            . "WHERE btrim(model.body_code) <> '' "
            . "ON CONFLICT (vehicle_model_id, normalized_name) DO UPDATE SET "
            . "name = EXCLUDED.name, year_from = EXCLUDED.year_from, "
            . "year_to = EXCLUDED.year_to, active = EXCLUDED.active, "
            . "updated_at = EXCLUDED.updated_at"
        );
        DB::statement(
            "INSERT INTO vehicle_engine_variants "
            . "(uuid, vehicle_model_id, vehicle_generation_id, code, "
            . "engine_type, power_hp, fingerprint, active, created_at, updated_at) "
            . "SELECT md5('vehicle-engine:' || model.id::text)::uuid, "
            . "model.id, generation.id, btrim(model.engine_index), "
            . "model.engine_type, model.horsepower, model.fingerprint, "
            . "model.active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP "
            . "FROM vehicle_models AS model "
            . "LEFT JOIN vehicle_generations AS generation "
            . "ON generation.vehicle_model_id = model.id "
            . "AND generation.normalized_name = "
            . "lower(btrim(regexp_replace(model.body_code, '\\s+', ' ', 'g'))) "
            . "WHERE btrim(model.engine_index) <> '' "
            . "ON CONFLICT (fingerprint) DO UPDATE SET "
            . "vehicle_generation_id = EXCLUDED.vehicle_generation_id, "
            . "code = EXCLUDED.code, engine_type = EXCLUDED.engine_type, "
            . "power_hp = EXCLUDED.power_hp, active = EXCLUDED.active, "
            . "updated_at = EXCLUDED.updated_at"
        );
        DB::statement(
            'UPDATE vehicles AS vehicle SET '
            . 'vehicle_generation_id = generation.id, '
            . 'vehicle_engine_variant_id = engine.id, '
            . 'vehicle_transmission_type_id = transmission.id, '
            . 'vehicle_drive_type_id = drive.id '
            . 'FROM vehicle_models AS model '
            . 'LEFT JOIN vehicle_generations AS generation '
            . 'ON generation.vehicle_model_id = model.id '
            . 'LEFT JOIN vehicle_engine_variants AS engine '
            . 'ON engine.vehicle_model_id = model.id '
            . 'LEFT JOIN vehicle_transmission_types AS transmission '
            . 'ON lower(transmission.name) = lower(model.transmission) '
            . 'LEFT JOIN vehicle_drive_types AS drive '
            . 'ON lower(drive.name) = lower(model.drive_type) '
            . 'WHERE vehicle.vehicle_model_id = model.id'
        );
    }

    /**
     * @param iterable<int, array<int, string>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(iterable $rows, bool $skipExactDuplicates = false): array
    {
        $columns = null;
        $result = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if ($columns === null) {
                $columns = $this->detectHeader($row);

                if ($columns === null) {
                    continue;
                }

                continue;
            }

            if ($this->emptyRow($row)) {
                continue;
            }

            $line = $index;
            $make = VehicleReferenceService::cleanName(
                (string) ($row[$columns['make']] ?? ''),
            );
            $model = VehicleReferenceService::cleanName(
                (string) ($row[$columns['model']] ?? ''),
            );

            if ($make === '' || $model === '') {
                Input::fail('Строка ' . $line . ': укажите марку и модель');
            }

            $sourceTransmission = trim((string) (
                $row[$columns['transmission']] ?? ''
            ));
            $sourceDriveType = trim((string) ($row[$columns['driveType']] ?? ''));
            $sourceEngineType = trim((string) ($row[$columns['engineType']] ?? ''));
            $attributes = [
                'name'            => $model,
                'engine_volume_liters' => $this->volume($row, $columns['engineVolume'], $line),
                'model_year_from' => $this->year($row, $columns['yearFrom'], $line),
                'model_year_to'   => $this->year($row, $columns['yearTo'], $line),
                'engine_index'    => $this->technicalText($row[$columns['engineIndex']] ?? ''),
                'body_code'       => $this->technicalText($row[$columns['bodyCode']] ?? ''),
                'horsepower'      => $this->horsepower($row, $columns['horsepower'], $line),
                'transmission'    => $this->transmission($sourceTransmission),
                'source_transmission' => $sourceTransmission,
                'drive_type'      => $this->driveType($sourceDriveType),
                'source_drive_type' => $sourceDriveType,
                'engine_type'     => $this->engineType($sourceEngineType),
                'source_engine_type' => $sourceEngineType,
            ];

            if (
                $attributes['model_year_from'] !== null
                && $attributes['model_year_to'] !== null
                && $attributes['model_year_to'] < $attributes['model_year_from']
            ) {
                Input::fail('Строка ' . $line . ': неверный диапазон модельных лет');
            }

            $fingerprint = VehicleReferenceService::fingerprint($make, $attributes);

            if (isset($seen[$fingerprint])) {
                if ($skipExactDuplicates && $seen[$fingerprint] === $row) {
                    $this->skippedDuplicates++;
                    continue;
                }
                Input::fail('Строка ' . $line . ': запись модели повторяется');
            }

            $seen[$fingerprint] = $row;
            $result[] = [
                'make'          => $make,
                'makeKey'       => VehicleReferenceService::normalizeName($make),
                'model'         => $model,
                'modelKey'      => VehicleReferenceService::normalizeName($model),
                'modelYearFrom' => $attributes['model_year_from'],
                'modelYearTo'   => $attributes['model_year_to'],
                'engineIndex'   => $attributes['engine_index'],
                'bodyCode'      => $attributes['body_code'],
                'horsepower'    => $attributes['horsepower'],
                'engineVolumeLiters' => $attributes['engine_volume_liters'],
                'transmission'  => $attributes['transmission'],
                'sourceTransmission' => $attributes['source_transmission'],
                'driveType'     => $attributes['drive_type'],
                'sourceDriveType' => $attributes['source_drive_type'],
                'engineType'    => $attributes['engine_type'],
                'sourceEngineType' => $attributes['source_engine_type'],
                'fingerprint'   => $fingerprint,
            ];
        }

        if ($columns === null || $result === []) {
            Input::fail('В XLSX не найдены строки справочника автомобилей');
        }

        if (count($result) > self::MAX_ROWS) {
            Input::fail('Импортируйте не более 100 000 строк за один раз');
        }

        return $result;
    }

    /**
     * @param array<int, string> $row
     *
     * @return array<string, int>|null
     */
    private function technicalText(string $value): string
    {
        $value = trim($value);
        return mb_strtolower($value) === 'нет данных' ? '' : $value;
    }

    private function volume(array $row, int $column, int $line): ?float
    {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value === '' || in_array(mb_strtolower($value), ['нет данных', 'не применимо'], true)) {
            return null;
        }
        $value = str_replace(',', '.', $value);
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 100) {
            Input::fail('Строка ' . $line . ': объём должен быть числом в литрах от 0,001 до 100');
        }
        return (float) $value;
    }

    private function detectHeader(array $row): ?array
    {
        $normalized = array_map(
            static fn (string $value): string => XlsxTableReader::normalizeHeader($value),
            $row,
        );
        $columns = [];

        foreach (self::HEADERS as $key => $aliases) {
            $column = $this->findColumn($normalized, $aliases);

            if ($column === null && $key === 'engineVolume') {
                $columns[$key] = -1;
                continue;
            }

            if ($column === null) {
                return null;
            }

            $columns[$key] = $column;
        }

        return $columns;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string> $aliases
     */
    private function findColumn(array $headers, array $aliases): ?int
    {
        $aliases = array_map(
            static fn (string $value): string => XlsxTableReader::normalizeHeader($value),
            $aliases,
        );

        foreach ($headers as $index => $header) {
            if (in_array($header, $aliases, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $row
     */
    private function year(array $row, int $column, int $line): ?int
    {
        $value = trim((string) ($row[$column] ?? ''));

        if ($value === '' || in_array(mb_strtolower($value), ['нет данных', 'не применимо'], true)) {
            return null;
        }

        if (!preg_match('/^[0-9]{4}$/', $value) || (int) $value < 1900 || (int) $value > 2100) {
            Input::fail(
                'Строка ' . $line . ': модельный год должен иметь формат YYYY',
            );
        }

        return (int) $value;
    }

    /**
     * @param array<int, string> $row
     */
    private function horsepower(array $row, int $column, int $line): ?string
    {
        $value = str_replace(',', '.', trim((string) ($row[$column] ?? '')));

        if ($value === '' || in_array(mb_strtolower($value), ['нет данных', 'не применимо'], true)) {
            return null;
        }

        if (!is_numeric($value) || (float) $value < 0.1 || (float) $value > 3000) {
            Input::fail('Строка ' . $line . ': мощность должна быть от 0,1 до 3000,0');
        }

        return number_format((float) $value, 1, '.', '');
    }

    private function transmission(string $source): ?string
    {
        $value = mb_strtolower($source);

        if ($value === '' || in_array(mb_strtolower($value), ['нет данных', 'не применимо'], true) || $value === 'null') {
            return null;
        }

        if (
            str_contains($value, 'мкпп')
            || str_contains($value, 'manual')
        ) {
            return 'МКПП';
        }

        if (
            str_contains($value, 'cvt')
            || str_contains($value, 'xtronic')
            || str_contains($value, 'lineartronic')
            || str_contains($value, 'variable')
            || $value === 'ivt'
        ) {
            return 'CVT';
        }

        if (
            preg_match('/(^|[^a-z])(dct|amt)([^a-z]|$)/u', $value) === 1
            || str_contains($value, 'ркпп')
            || str_contains($value, 'робот')
            || str_contains($value, 'dual')
            || str_contains($value, 'clutch')
            || str_contains($value, 's-tronic')
            || str_contains($value, 'sequential')
            || str_contains($value, 'dualogic')
            || str_contains($value, 'e-gear')
            || str_contains($value, 'tct')
            || str_contains($value, 'isr')
            || str_contains($value, 'ssg')
        ) {
            return 'РКПП';
        }

        if (
            str_contains($value, 'редуктор')
            || str_contains($value, 'электро')
            || str_contains($value, 'reduct')
            || str_contains($value, 'single')
            || str_contains($value, '1-speed')
            || str_contains($value, 'e-shifter')
            || str_contains($value, 'planetary')
            || str_contains($value, 'transaxle')
            || str_contains($value, 'e-tech')
        ) {
            return 'электро';
        }

        if (
            str_contains($value, 'акпп')
            || str_contains($value, 'automatic')
            || str_contains($value, 'steptronic')
            || str_contains($value, 'tiptronic')
            || str_contains($value, 'hydra')
            || str_contains($value, 'speed')
        ) {
            return 'АКПП';
        }

        return null;
    }

    private function driveType(string $source): ?string
    {
        $value = mb_strtolower($source);

        if ($value === '' || in_array(mb_strtolower($value), ['нет данных', 'не применимо'], true) || $value === 'null') {
            return null;
        }

        if (in_array($value, VehicleReferenceService::DRIVE_TYPES, true)) {
            return $value;
        }

        if (str_contains($value, '4-wheel drive') || str_contains($value, '4wd')) {
            return 'полный';
        }

        return null;
    }

    private function engineType(string $source): ?string
    {
        $value = mb_strtolower($source);

        if ($value === '' || in_array(mb_strtolower($value), ['нет данных', 'не применимо'], true) || $value === 'null') {
            return null;
        }

        return match ($value) {
            'бензин',
            'бензин/газ',
            'спиртовое топливо' => 'Бензин',
            'газ' => 'газ',
            'газ/бензин' => 'газ/бензин',
            'водородный (fcev)' => 'водородный',
            'дизель'   => 'дизель',
            'гибрид'   => 'гибрид',
            'электро', 'электрический' => 'электро',
            default    => null,
        };
    }

    /**
     * @param array<int, string> $row
     */
    private function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
