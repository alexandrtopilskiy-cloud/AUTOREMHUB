<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quantity
 * @property string|null $price
 * @property string|null $hourly_rate
 * @property string|null $norm_hours
 * @property string|null $line_amount
 * @property bool $norm_is_manual
 * @property string|null $actual_hours
 * @property bool $is_maintenance
 * @property int|null $work_type_id
 * @property int|null $time_norm_id
 * @property-read WorkType|null $workType
 * @property-read TimeNorm|null $timeNorm
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

    /** @return BelongsTo<WorkType, $this> */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /** @return BelongsTo<TimeNorm, $this> */
    public function timeNorm(): BelongsTo
    {
        return $this->belongsTo(TimeNorm::class);
    }
}
