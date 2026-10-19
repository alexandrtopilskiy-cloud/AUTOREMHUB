<?php

declare(strict_types=1);

namespace App\Models;

/** Manufacturer catalog with an unpublished station proposal and optimistic revision. */
final class PartManufacturer extends Entity
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'proposed_active' => 'boolean', 'revision' => 'integer'];
    }
}
