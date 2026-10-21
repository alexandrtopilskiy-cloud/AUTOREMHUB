<?php

declare(strict_types=1);

namespace App\Models;

/**
 * @property string $code
 * @property string $name
 * @property bool $active
 */
final class VehicleTransmissionType extends Entity
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
