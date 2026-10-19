<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\WorkCatalogReferenceException;
use App\Models\User;
use App\Models\WorkType;

final class WorkCatalogValidator
{
    private const MAX_ERRORS = 1000;

    /**
     * @param array{
     *     works: array<int, array<string, mixed>>,
     *     norms: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>
     * } $parsed
     *
     * @return array{
     *     works: array<int, array<string, mixed>>,
     *     norms: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>,
     *     summary: array<string, int>
     * }
     */
    public function validate(User $user, array $parsed): array
    {
        $errors = $parsed['errors'];
        $works = $this->validateWorks($parsed['works'], $errors);
        $resolver = new WorkCatalogReferenceResolver($user->tenantId());
        $norms = $this->validateNorms($parsed['norms'], $works, $resolver, $errors);
        $this->assertNormCoverage($works, $norms, $errors);
        $this->detectNormConflicts($norms, $errors);

        $errors = array_slice($errors, 0, self::MAX_ERRORS);

        return [
            'works' => $works,
            'norms' => $norms,
            'errors' => $errors,
            'summary' => [
                'workRows' => count($parsed['works']),
                'acceptedWorks' => count($works),
                'normRows' => count($parsed['norms']),
                'acceptedNorms' => count($norms),
                'confirmedNorms' => count(array_filter(
                    $norms,
                    static fn (array $norm): bool => $norm['hours'] !== null,
                )),
                'emptyNorms' => count(array_filter(
                    $norms,
                    static fn (array $norm): bool => $norm['hours'] === null,
                )),
                'errors' => count($errors),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $errors
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateWorks(array $rows, array &$errors): array
    {
        $result = [];
        $codes = [];
        $names = [];

        foreach ($rows as $row) {
            $before = count($errors);
            $code = strtoupper((string) $row['workCode']);
            $groupCode = strtoupper((string) $row['groupCode']);
            $name = trim((string) $row['name']);
            $group = trim((string) $row['group']);

            if (!preg_match('/^[A-Z]{2}-[0-9]{5}$/', $code)) {
                $this->addError(
                    $errors,
                    $row,
                    'workCode',
                    'INVALID_WORK_CODE',
                    'Код должен иметь формат AA-BBBBB',
                );
            }

            if (!preg_match('/^[A-Z]{2}$/', $groupCode)) {
                $this->addError(
                    $errors,
                    $row,
                    'groupCode',
                    'INVALID_GROUP_CODE',
                    'Код группы должен состоять из двух латинских букв',
                );
            } elseif (!str_starts_with($code, $groupCode . '-')) {
                $this->addError(
                    $errors,
                    $row,
                    'groupCode',
                    'GROUP_CODE_MISMATCH',
                    'Код работы должен начинаться с кода своей группы',
                );
            }

            if (!in_array($group, config('sto.work_groups'), true)) {
                $this->addError(
                    $errors,
                    $row,
                    'group',
                    'UNKNOWN_WORK_GROUP',
                    'Группа отсутствует в закрытом перечне',
                );
            } elseif ($groupCode !== WorkType::groupCodeFor($group)) {
                $this->addError(
                    $errors,
                    $row,
                    'groupCode',
                    'INVALID_GROUP_CODE',
                    'Для выбранной группы ожидается код '
                    . WorkType::groupCodeFor($group),
                );
            }

            if ($name === '' || mb_strlen($name) > 500 || !str_contains($name, ' - ')) {
                $this->addError(
                    $errors,
                    $row,
                    'name',
                    'INVALID_WORK_NAME',
                    'Используйте формат «деталь или система - действие» '
                    . 'длиной до 500 символов',
                );
            }

            $nameKey = $this->canonical($name);
            if (isset($codes[$code])) {
                $this->addError(
                    $errors,
                    $row,
                    'workCode',
                    'DUPLICATE_WORK_CODE',
                    'Код работы повторяется в строке ' . $codes[$code],
                );
            }

            if ($nameKey !== '' && isset($names[$nameKey])) {
                $this->addError(
                    $errors,
                    $row,
                    'name',
                    'DUPLICATE_WORK_NAME',
                    'Наименование смыслово повторяет строку ' . $names[$nameKey],
                );
            }

            $active = $this->boolean($row, 'active', $errors);
            $maintenance = $this->boolean($row, 'isMaintenance', $errors);
            $sourceRow = trim((string) $row['sourceRow']);
            if (
                $sourceRow !== ''
                && (!ctype_digit($sourceRow) || (int) $sourceRow < 1)
            ) {
                $this->addError(
                    $errors,
                    $row,
                    'sourceRow',
                    'INVALID_SOURCE_ROW',
                    'Исходная строка должна быть положительным '
                    . 'целым числом',
                );
            }

            $codes[$code] = (int) $row['_row'];
            $names[$nameKey] = (int) $row['_row'];

            if (count($errors) !== $before) {
                continue;
            }

            $result[] = [
                'row' => (int) $row['_row'],
                'code' => $code,
                'group_code' => $groupCode,
                'group_name' => $group,
                'name' => $name,
                'is_maintenance' => $maintenance,
                'active' => $active,
                'source_row' => $sourceRow === '' ? null : (int) $sourceRow,
                'source_name' => trim((string) $row['source']),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $works
     * @param array<int, array<string, mixed>> $errors
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateNorms(
        array $rows,
        array $works,
        WorkCatalogReferenceResolver $resolver,
        array &$errors,
    ): array {
        $result = [];
        $workCodes = array_fill_keys(array_column($works, 'code'), true);
        $normIds = [];

        foreach ($rows as $row) {
            $before = count($errors);
            $normId = strtoupper(trim((string) $row['normId']));
            $workCode = strtoupper(trim((string) $row['workCode']));

            if (!preg_match('/^[A-Z]{2}-[0-9]{5}-N[0-9]{3}$/', $normId)) {
                $this->addError(
                    $errors,
                    $row,
                    'normId',
                    'INVALID_NORM_ID',
                    'norm_id должен иметь формат AA-BBBBB-NNN',
                );
            }

            if (!isset($workCodes[$workCode])) {
                $this->addError(
                    $errors,
                    $row,
                    'workCode',
                    'WORK_NOT_FOUND',
                    'Код работы отсутствует на листе «Работы» '
                    . 'или содержит ошибку',
                );
            }

            if (isset($normIds[$normId])) {
                $this->addError(
                    $errors,
                    $row,
                    'normId',
                    'DUPLICATE_NORM_ID',
                    'norm_id повторяется в строке ' . $normIds[$normId],
                );
            }
            $normIds[$normId] = (int) $row['_row'];

            $hours = $this->hours($row, $errors);
            $active = $this->boolean($row, 'active', $errors);

            try {
                $references = $resolver->resolve($row);
            } catch (WorkCatalogReferenceException $exception) {
                $this->addError(
                    $errors,
                    $row,
                    $exception->field,
                    'INVALID_APPLICABILITY',
                    $exception->getMessage(),
                );
                $references = [];
            }

            if (count($errors) !== $before) {
                continue;
            }

            $result[] = array_merge($references, [
                'row' => (int) $row['_row'],
                'norm_id' => $normId,
                'work_code' => $workCode,
                'hours' => $hours,
                'active' => $active,
                'source' => trim((string) $row['source']),
                'vin' => strtoupper(trim((string) $row['vin'])),
                'make' => trim((string) $row['make']),
                'model' => trim((string) $row['model']),
                'generation' => trim((string) $row['generation']),
                'engine' => trim((string) $row['engine']),
                'transmission' => trim((string) $row['transmission']),
                'drive' => trim((string) $row['drive']),
            ]);
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $works
     * @param array<int, array<string, mixed>> $norms
     * @param array<int, array<string, mixed>> $errors
     */
    private function assertNormCoverage(array $works, array $norms, array &$errors): void
    {
        $covered = array_fill_keys(array_column($norms, 'work_code'), true);

        foreach ($works as $work) {
            if (!isset($covered[$work['code']])) {
                $errors[] = [
                    'sheet' => 'Работы',
                    'row' => $work['row'],
                    'cell' => 'A' . $work['row'],
                    'field' => 'workCode',
                    'value' => $work['code'],
                    'code' => 'NORM_REQUIRED',
                    'message' => 'Для каждой работы нужна отдельная '
                        . 'запись с norm_id',
                ];
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $norms
     * @param array<int, array<string, mixed>> $errors
     */
    private function detectNormConflicts(array $norms, array &$errors): void
    {
        $groups = [];
        foreach ($norms as $norm) {
            if (!$norm['active']) {
                continue;
            }

            $groups[$norm['work_code']][] = $norm;
        }

        foreach ($groups as $workNorms) {
            for ($left = 0, $count = count($workNorms); $left < $count; $left++) {
                for ($right = $left + 1; $right < $count; $right++) {
                    $one = $workNorms[$left];
                    $two = $workNorms[$right];

                    if ($this->score($one) !== $this->score($two) || !$this->overlaps($one, $two)) {
                        continue;
                    }

                    $errors[] = [
                        'sheet' => 'Нормы времени',
                        'row' => $two['row'],
                        'cell' => 'A' . $two['row'],
                        'field' => 'normId',
                        'value' => $two['norm_id'],
                        'code' => 'AMBIGUOUS_NORM_RULE',
                        'message' => 'Правило пересекается без приоритета с '
                            . $one['norm_id'],
                    ];
                }
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function score(array $row): string
    {
        $rank = $row['vehicle_id'] !== null ? 500
            : ($row['vehicle_generation_id'] !== null ? 400
                : ($row['vehicle_make_id'] !== null && $row['vehicle_model_id'] !== null ? 300
                    : ($row['vehicle_make_id'] !== null ? 200 : 100)));

        $constraints = $rank === 400
            ? count(array_filter(
                $this->dimensions($row),
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ))
            : 0;

        return $rank . ':' . $constraints;
    }

    /** @param array<string, mixed> $one @param array<string, mixed> $two */
    private function overlaps(array $one, array $two): bool
    {
        foreach (array_keys($this->dimensions($one)) as $field) {
            if ($one[$field] !== null && $two[$field] !== null && $one[$field] !== $two[$field]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function dimensions(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'vehicle_id',
            'vehicle_make_id',
            'vehicle_model_id',
            'vehicle_generation_id',
            'vehicle_engine_variant_id',
            'vehicle_transmission_type_id',
            'vehicle_drive_type_id',
            'modification_code',
        ]));
    }

    /** @param array<string, mixed> $row @param array<int, array<string, mixed>> $errors */
    private function hours(array $row, array &$errors): ?string
    {
        $value = str_replace(',', '.', trim((string) $row['hours']));
        if ($value === '') {
            return null;
        }

        if (
            !is_numeric($value)
            || (float) $value <= 0
            || (float) $value > 100000
        ) {
            $this->addError(
                $errors,
                $row,
                'hours',
                'INVALID_HOURS',
                'Норма должна быть положительным числом до 100000 '
                . 'или пустой',
            );
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    /** @param array<string, mixed> $row @param array<int, array<string, mixed>> $errors */
    private function boolean(array $row, string $field, array &$errors): bool
    {
        $value = mb_strtolower(trim((string) $row[$field]));
        if (in_array($value, ['1', 'да', 'true'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'нет', 'false'], true)) {
            return false;
        }

        $this->addError(
            $errors,
            $row,
            $field,
            'INVALID_BOOLEAN',
            'Допустимые значения: Да или Нет',
        );
        return false;
    }

    /** @param array<int, array<string, mixed>> $errors @param array<string, mixed> $row */
    private function addError(array &$errors, array $row, string $field, string $code, string $message): void
    {
        $errors[] = [
            'sheet' => $row['_sheet'],
            'row' => $row['_row'],
            'cell' => $row['_cells'][$field] ?? 'A' . $row['_row'],
            'field' => $field,
            'value' => $row[$field] ?? '',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function canonical(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        return (string) preg_replace('/\s+/u', ' ', str_replace('ё', 'е', $normalized));
    }
}
