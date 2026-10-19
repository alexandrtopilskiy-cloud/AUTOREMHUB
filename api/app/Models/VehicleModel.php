<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $vehicle_make_id
 * @property string $name
 * @property string $normalized_name
 * @property int|null $model_year_from
 * @property int|null $model_year_to
 * @property string $engine_index
 * @property string $body_code
 * @property string|null $horsepower
 * @property string|null $transmission
 * @property string $source_transmission
 * @property string|null $drive_type
 * @property string $source_drive_type
 * @property string|null $engine_type
 * @property string $source_engine_type
 * @property string $fingerprint
 * @property bool $active
 *
 * @property-read VehicleMake $make
 */
final class VehicleModel extends Entity
{
    protected function casts(): array
    {
        return [
            'model_year_from' => 'integer',
            'model_year_to'   => 'integer',
            'horsepower'      => 'decimal:1',
            'engine_volume_liters' => 'decimal:3',
            'active'          => 'boolean',
        ];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(VehicleGeneration::class);
    }

    public function engines(): HasMany
    {
        return $this->hasMany(VehicleEngineVariant::class);
    }
}
