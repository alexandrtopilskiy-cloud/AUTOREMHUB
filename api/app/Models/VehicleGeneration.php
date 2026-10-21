<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $vehicle_model_id
 * @property string $name
 * @property string $normalized_name
 * @property int|null $year_from
 * @property int|null $year_to
 * @property bool $active
 *
 * @property-read VehicleModel $model
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VehicleEngineVariant> $engines
 */
final class VehicleGeneration extends Entity
{
    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to'   => 'integer',
            'active'    => 'boolean',
        ];
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    /** @return HasMany<VehicleEngineVariant, $this> */
    public function engines(): HasMany
    {
        return $this->hasMany(VehicleEngineVariant::class);
    }
}
