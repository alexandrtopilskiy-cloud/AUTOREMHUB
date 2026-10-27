<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $active
 * @property float $rating
 * @property array<string, mixed> $profile
 * @property string $workday_start
 * @property string $workday_end
 * @property array<int, int> $working_days
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkOrder> $workOrders
 */
final class Station extends Entity
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'rating' => 'float',
            'profile' => 'array',
            'working_days' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
