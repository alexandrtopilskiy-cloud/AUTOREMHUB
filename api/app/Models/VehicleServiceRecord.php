<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $mileage
 * @property CarbonImmutable $performed_at
 * @property bool $is_maintenance
 */
final class VehicleServiceRecord extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mileage'        => 'integer',
            'performed_at'   => 'immutable_datetime',
            'is_maintenance' => 'boolean',
        ];
    }
}