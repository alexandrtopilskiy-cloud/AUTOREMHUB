<?php

declare(strict_types=1);

namespace App\Models;

/**
 * @property int|null $interval_km
 * @property int|null $interval_months
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