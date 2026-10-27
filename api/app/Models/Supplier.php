<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property bool $active
 * @property string $name
 * @property string $contact
 * @property string $tax_id
 * @property string|null $legal_form
 * @property array<string, mixed>|null $requisites
 * @property array<int, array<string, mixed>>|null $contacts
 * @property int|null $created_by_organization_id
 */
final class Supplier extends Entity
{
    use HasFactory;

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
