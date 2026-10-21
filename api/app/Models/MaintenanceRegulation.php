<?php

declare(strict_types=1);

namespace App\Models;

/**
 * @property string $name
 * @property string $vehicle_model
 * @property int|null $interval_km
 * @property int|null $interval_months
 * @property string $source
 * @property bool $active
 */
final class MaintenanceRegulation extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_km'     => 'integer',
            'interval_months' => 'integer',
            'active'          => 'boolean',
        ];
    }
}