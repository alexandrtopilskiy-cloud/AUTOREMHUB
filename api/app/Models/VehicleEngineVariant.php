<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $vehicle_model_id
 * @property int|null $vehicle_generation_id
 * @property string $code
 * @property string|null $engine_type
 * @property string|null $power_hp
 * @property string $fingerprint
 * @property bool $active
 *
 * @property-read VehicleModel $model
 * @property-read VehicleGeneration|null $generation
 */
final class VehicleEngineVariant extends Entity
{
    protected function casts(): array
    {
        return [
            'power_hp' => 'decimal:1',
            'active'   => 'boolean',
        ];
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    /** @return BelongsTo<VehicleGeneration, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'vehicle_generation_id');
    }
}
