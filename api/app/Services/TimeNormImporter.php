<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\TimeNorm;
use App\Models\TimeNormApplicability;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkType;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;

final class TimeNormImporter
{
    private const MAX_ROWS = 10_000;

    private const WORK_HEADERS = [
        'работа',
        'вид работы',
        'вид работ',
        'наименование работы',
        'наименование работ',
        'work',
        'work type',
        'Наименование',
    ];

    private const MODEL_HEADERS = [
        'модель',
        'модель автомобиля',
        'марка и модель',
        'марка/модель',
        'vehicle model',
    ];

    private const HOURS_HEADERS = [
        'норма, ч',
        'норма времени',
        'норма времени, ч',
        'норма времени, н/ч',
        'норматив времени',
        'нормо-час',
        'нормо/час',
        'трудоемкость',
        'трудоёмкость',
        'часы',
        'hours',
        'Норма времени',
    ];

    private const SOURCE_HEADERS = [
        'источник',
        'source',
    ];

    public function __construct(
        private readonly XlsxTableReader $reader,
        private readonly CatalogAuditService $audit,
    ) {
    }

    /**
     * @return array{created: int, updated: int, workTypesCreated: int, total: int, dryRun: bool}
     */
    public function import(
        int $tenantId,
        string $path,
        ?User $actor = null,
        bool $dryRun = false,
    ): array
    {
        if (
            $actor !== null
            && ($actor->role !== UserRole::Manager || $actor->tenantId() !== $tenantId)
        ) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Импорт норм времени доступен только '
                . 'Управляющей компании',
            );
        }

        $rows = $this->normalizeRows($this->reader->read(
            $path,
            [self::WORK_HEADERS, self::HOURS_HEADERS],
        ));

        return DB::transaction(function () use ($tenantId, $rows, $actor, $dryRun): array {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            $general = $dryRun
                ? $this->findApplicability($tenantId, 'general')
                : $this->applicability($tenantId, 'general', 'Общая');
            $special = $dryRun
                ? $this->findApplicability($tenantId, 'special')
                : $this->applicability($tenantId, 'special', 'Специальная');

            $created = 0;
            $updated = 0;
            $workTypesCreated = 0;
            $dryRunWorkTypes = [];

            foreach ($rows as $row) {
                $workType = WorkType::query()
                    ->where('tenant_id', $tenantId)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($row['work'])])
                    ->lockForUpdate()
                    ->first();

                if ($workType === null) {
                    if ($dryRun) {
                        $workTypeKey = mb_strtolower($row['work']);

                        if (!isset($dryRunWorkTypes[$workTypeKey])) {
                            $dryRunWorkTypes[$workTypeKey] = true;
                            $workTypesCreated++;
                        }

                        $created++;
                        continue;
                    }

                    $workTypesCreated++;

                    $workType = WorkType::query()->create([
                        'tenant_id'     => $tenantId,
                        'name'          => $row['work'],
                        'is_maintenance' => false,
                        'active'        => true,
                    ]);

                    $this->audit->record(
                        $tenantId,
                        $actor,
                        CatalogKind::WorkTypes,
                        $workType,
                        'import_create',
                        null,
                        $workType->attributesToArray(),
                    );
                }

                $applicability = $row['vehicleModel'] === ''
                    ? $general
                    : $special;
                $normQuery = TimeNorm::query()
                    ->where('work_type_id', $workType->id)
                    ->where('vehicle_model', $row['vehicleModel']);
                $norm = $applicability === null
                    ? $normQuery->whereNull('applicability_id')->lockForUpdate()->first()
                    : (clone $normQuery)
                        ->where('applicability_id', $applicability->id)
                        ->lockForUpdate()
                        ->first();

                // Старые строки могли быть созданы до появления применимости.
                // Они безопасно переводятся в нужную область, но строка «По авто»
                // с таким же текстовым названием модели не перезаписывается.
                if ($norm === null && $applicability !== null) {
                    $norm = $normQuery
                        ->whereNull('applicability_id')
                        ->lockForUpdate()
                        ->first();
                }

                if ($norm === null) {
                    $created++;
                } else {
                    $updated++;
                }

                if ($dryRun) {
                    continue;
                }

                $before = $norm?->attributesToArray();
                $norm ??= new TimeNorm(['work_type_id' => $workType->id]);
                $norm->fill([
                    'hours'           => $row['hours'],
                    'applicability_id' => $applicability?->id,
                    'vehicle_model'   => $row['vehicleModel'],
                    'vehicle_make_id' => null,
                    'vehicle_model_id' => null,
                    'source'          => $row['source'],
                ]);
                $norm->save();

                $this->audit->record(
                    $tenantId,
                    $actor,
                    CatalogKind::TimeNorms,
                    $norm,
                    $before === null ? 'import_create' : 'import_update',
                    $before,
                    $norm->attributesToArray(),
                );
            }

            return [
                'created'          => $created,
                'updated'          => $updated,
                'workTypesCreated' => $workTypesCreated,
                'total'            => count($rows),
                'dryRun'           => $dryRun,
            ];
        });
    }

    private function applicability(
        int $tenantId,
        string $code,
        string $name,
    ): TimeNormApplicability {
        return TimeNormApplicability::query()->firstOrCreate(
            [
                'tenant_id'       => $tenantId,
                'normalized_name' => mb_strtolower($name),
            ],
            [
                'code'      => $code,
                'name'      => $name,
                'is_system' => true,
                'active'    => true,
            ],
        );
    }

    private function findApplicability(
        int $tenantId,
        string $code,
    ): ?TimeNormApplicability {
        return TimeNormApplicability::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();
    }

    /**
     * @param array<int, array<int, string>> $rows
     *
     * @return array<int, array{work: string, vehicleModel: string, hours: string|null, source: string}>
     */
    private function normalizeRows(array $rows): array
    {
        $header = null;
        $result = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if ($header === null) {
                $header = $this->detectHeader($row);

                continue;
            }

            if ($this->emptyRow($row)) {
                continue;
            }

            $work = trim((string) ($row[$header['work']] ?? ''));
            $model = $header['model'] === null
                ? ''
                : trim((string) ($row[$header['model']] ?? ''));
            $source = $header['source'] === null
                ? ''
                : trim((string) ($row[$header['source']] ?? ''));
            $hours = $this->normalizeHours((string) ($row[$header['hours']] ?? ''), $index + 1);

            if ($work === '' || mb_strlen($work) > 255) {
                Input::fail('Строка ' . ($index + 1) . ': укажите наименование работы');
            }

            if (mb_strlen($model) > 255 || mb_strlen($source) > 10_000) {
                Input::fail(
                    'Строка ' . ($index + 1)
                    . ': превышена допустимая длина значения'
                );
            }

            $key = mb_strtolower($work . "\0" . $model);

            $seen[$key] = true;
            $result[] = [
                'work'         => $work,
                'vehicleModel' => $model,
                'hours'        => $hours,
                'source'       => $source,
            ];
        }

        if ($header === null) {
            Input::fail('Ожидаются колонки «Работа» и «Норма, ч»');
        }

        if ($result === []) {
            Input::fail('В файле не найдено норм времени');
        }

        if (count($result) > self::MAX_ROWS) {
            Input::fail('Импортируйте не более 10 000 норм за один раз');
        }

        return $result;
    }

    /**
     * @param array<int, string> $row
     *
     * @return array{work: int, model: int|null, hours: int, source: int|null}|null
     */
    private function detectHeader(array $row): ?array
    {
        $headers = array_map(
            static fn (string $value): string => XlsxTableReader::normalizeHeader($value),
            $row,
        );
        $work = $this->findColumn($headers, self::WORK_HEADERS);
        $hours = $this->findColumn($headers, self::HOURS_HEADERS);

        if ($work === null || $hours === null) {
            return null;
        }

        return [
            'work'   => $work,
            'model'  => $this->findColumn($headers, self::MODEL_HEADERS),
            'hours'  => $hours,
            'source' => $this->findColumn($headers, self::SOURCE_HEADERS),
        ];
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
    private function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHours(?string $value, int $row): ?string
    {
        // Пустая ячейка — норма времени не указана.
        if ($value === null || trim($value) === '' || $value == 0) {
            return null;
        }

        $normalized = str_replace([',', ' '], ['.', ''], trim($value));

        if (
            !preg_match('/^\d+(?:\.\d{1,3})?$/', $normalized)
            || (float) $normalized > 100_000
        ) {
            Input::fail(
                'Строка ' . $row
                . ': норма времени должна быть положительным числом',
            );
        }

        return $normalized;
    }
}
