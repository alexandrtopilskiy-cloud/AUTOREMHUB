<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/** Manufacturer catalog with an unpublished station proposal and optimistic revision. */
final class PartManufacturer extends Entity
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'proposed_active' => 'boolean',
            'revision' => 'integer',
        ];
    }
}
