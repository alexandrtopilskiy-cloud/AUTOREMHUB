<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonKind;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Контактное лицо или водитель из справочника организации.
 *
 * @property int $tenant_id
 * @property int $organization_id
 * @property PersonKind $kind
 * @property string $last_name
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $phone
 * @property bool $active
 *
 * @property-read Organization $organization
 */
final class OrganizationPerson extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'kind'   => PersonKind::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function vehicleContacts(): HasMany
    {
        return $this->hasMany(VehicleContact::class);
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ]));
    }

    public static function forUser(User $user): Builder
    {
        $query = self::query()
            ->where('tenant_id', $user->tenantId())
            ->where('active', true);

        if ($user->role === UserRole::Customer) {
            $query->where('organization_id', $user->organization_id);
        }

        if ($user->role === UserRole::Station) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
