<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $mileage
 * @property string $total
 * @property CarbonImmutable|null $completed_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkOrderItem> $items
 */
final class WorkOrder extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mileage'      => 'integer',
            'total'        => 'decimal:2',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'work_order_id');
    }
}
