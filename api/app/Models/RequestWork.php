<?php

declare(strict_types=1);

namespace App\Models;

/**
 * @property string $quantity
 * @property string|null $price
 * @property string|null $hourly_rate
 * @property string|null $norm_hours
 * @property string|null $line_amount
 * @property bool $norm_is_manual
 * @property string|null $actual_hours
 * @property bool $is_maintenance
 */
final class RequestWork extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity'       => 'decimal:3',
            'price'          => 'decimal:2',
            'hourly_rate'    => 'decimal:2',
            'norm_hours'     => 'decimal:3',
            'actual_hours'   => 'decimal:3',
            'line_amount'    => 'decimal:2',
            'norm_is_manual' => 'boolean',
            'is_maintenance' => 'boolean',
        ];
    }
}
