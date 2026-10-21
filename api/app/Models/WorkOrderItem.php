<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkOrderItemKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $quantity
 * @property string $price
 * @property string $norm_hours
 * @property string|null $actual_hours
 * @property bool $is_maintenance
 * @property WorkOrderItemKind $kind
 */
final class WorkOrderItem extends Entity
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity'       => 'decimal:3',
            'price'          => 'decimal:2',
            'norm_hours'     => 'decimal:3',
            'actual_hours'   => 'decimal:3',
            'is_maintenance' => 'boolean',
            'kind'           => WorkOrderItemKind::class,
        ];
    }
}
