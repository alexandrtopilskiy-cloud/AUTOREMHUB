<?php

declare(strict_types=1);

namespace App\Models;

final class VehicleDriveType extends Entity
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
