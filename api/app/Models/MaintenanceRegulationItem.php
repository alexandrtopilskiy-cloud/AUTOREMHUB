<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $maintenance_regulation_id
 * @property int $work_type_id
 * @property int|null $time_norm_id
 * @property string $quantity
 *
 * @property-read WorkType $workType
 * @property-read TimeNorm|null $timeNorm
 */
final class MaintenanceRegulationItem extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
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