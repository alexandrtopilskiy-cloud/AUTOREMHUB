<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrganizationKind $kind
 * @property-read Tenant $tenant
 */
final class Organization extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'kind'     => OrganizationKind::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(OrganizationPerson::class);
    }
}
