<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $normalized_name
 * @property bool $active
 */
final class VehicleMake extends Entity
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }
}
