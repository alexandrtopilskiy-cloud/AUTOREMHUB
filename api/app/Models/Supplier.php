<?php

declare(strict_types=1);

namespace App\Models;

/**
 * @property bool $active
 */
final class Supplier extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requisites' => 'array',
            'contacts' => 'array',
            'created_by_organization_id' => 'integer',
        ];
    }
}
