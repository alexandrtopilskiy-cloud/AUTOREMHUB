<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonKind;
use App\Support\RussianPhone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $vehicle_id
 * @property int|null $organization_person_id
 * @property PersonKind $kind
 * @property string $last_name
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $phone
 * @property bool $active
 *
 * @property-read Vehicle $vehicle
 * @property-read OrganizationPerson|null $organizationPerson
 */
final class VehicleContact extends Entity
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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function organizationPerson(): BelongsTo
    {
        return $this->belongsTo(OrganizationPerson::class);
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ]));
    }

    /**
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        return [
            'personId'   => $this->organizationPerson?->uuid ?? '',
            'kind'       => $this->kind->value,
            'lastName'   => $this->last_name,
            'firstName'  => $this->first_name,
            'middleName' => $this->middle_name ?? '',
            'fullName'   => $this->fullName(),
            'phone'      => RussianPhone::format($this->phone),
        ];
    }
}
