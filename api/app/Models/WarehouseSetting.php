<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Наценка на товары организации: одинаковая (flat) или по прогрессии (progressive).
 *
 * @property int $organization_id
 * @property string $markup_mode
 * @property string $markup_percent
 * @property array<int, array{from: float|null, to: float|null, percent: float}> $markup_tiers
 * @property string $cost_method fifo | lifo | average
 */
final class WarehouseSetting extends Entity
{
    public const COST_METHODS = [
        'fifo' => 'ФИФО — первым пришёл, первым списан',
        'lifo' => 'ЛИФО — последним пришёл, первым списан',
        'average' => 'Средневзвешенная цена',
    ];

    protected $attributes = [
        'cost_method' => 'average',
        'markup_mode' => 'flat',
        'markup_percent' => 0,
        'markup_tiers' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'markup_tiers' => 'array',
        ];
    }
}
