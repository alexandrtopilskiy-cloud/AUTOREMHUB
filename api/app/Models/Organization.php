<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Enums\OrganizationKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrganizationKind $kind
 * @property-read Tenant $tenant
 */
final class Organization extends Entity
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vat_rate'         => 'decimal:2',
            'kind'             => OrganizationKind::class,
            'edo_provider'     => EdoProviderEnum::class,
            'edo_registered_at' => 'immutable_datetime',
            'markup_percent' => 'decimal:2',
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

    public function edoDocuments(): HasMany
    {
        return $this->hasMany(EdoDocument::class, 'organization_id');
    }

    public function incomingEdoDocuments(): HasMany
    {
        return $this->hasMany(EdoDocument::class, 'counterparty_id');
    }

    public function isRegisteredInEdo(): bool
    {
        return $this->edo_client_id !== null && $this->edo_edi_code !== null;
    }

    /**
     * Оператор, обслуживающий эту организацию.
     * null → использовать config('edo.default').
     */
    public function edoProvider(): ?EdoProviderEnum
    {
        return $this->edo_provider;
    }

    public function markupPercent(): float
    {
        if ($this->markup_percent !== null) {
            return (float) $this->markup_percent;
        }

        return (float) config('edo.manager_markup_percent', 10.0);
    }

    public function hasInn(): bool
    {
        return trim((string) $this->inn) !== '';
    }

    public function kppOrNull(): ?string
    {
        $kpp = trim((string) $this->kpp);

        return $kpp !== '' ? $kpp : null;
    }
}
