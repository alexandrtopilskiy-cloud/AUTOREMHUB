<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceReason;
use App\Models\TimeNormApplicability;

final class ReferenceSeeder
{
    private const TIME_NORM_APPLICABILITIES = [
        ['general', 'Общая'],
        ['special', 'Специальная'],
        ['vehicle', 'По авто'],
    ];

    /**
     * Начальные причины обращения.
     *
     * Формат: [code, name, requires_diagnosis, is_maintenance, children]
     * Формат children: [code_suffix, name]
     *
     * @var array<int, array{0: string, 1: string, 2: bool, 3: bool, 4: array<int, array{0: string, 1: string}>}>
     */
    private const REASONS = [
        [
            'diagnostics',
            'Диагностика',
            true,
            false,
            [
                ['suspension', 'Ходовая часть'],
                ['brakes', 'Тормозная система'],
                ['electrical', 'Электрика'],
                ['computer', 'Компьютерная диагностика'],
            ],
        ],
        [
            'maintenance',
            'ТО',
            false,
            true,
            [],
        ],
    ];

    public function seed(int $tenantId): void
    {
        foreach (self::TIME_NORM_APPLICABILITIES as [$code, $name]) {
            TimeNormApplicability::query()->updateOrCreate(
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

        foreach (self::REASONS as [$code, $name, $requiresDiagnosis, $isMaintenance, $children]) {
            $parent = ServiceReason::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code'      => $code,
                ],
                [
                    'name'                => $name,
                    'requires_diagnosis'  => $requiresDiagnosis,
                    'is_maintenance'      => $isMaintenance,
                ],
            );

            foreach ($children as [$suffix, $title]) {
                ServiceReason::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'code'      => $code . '_' . $suffix,
                    ],
                    [
                        'parent_id'          => $parent->id,
                        'name'               => $title,
                        'requires_diagnosis' => true,
                    ],
                );
            }
        }
    }
}
