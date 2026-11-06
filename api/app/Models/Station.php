<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $active
 * @property float $rating
 * @property array<string, mixed> $profile
 * @property string $workday_start
 * @property string $workday_end
 * @property array<int, int> $working_days
 * @property string|null $agent_discount_percent
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkOrder> $workOrders
 */
final class Station extends Entity
{
    use HasFactory;

    /** Агентская скидка СТО по умолчанию, %. */
    public const DEFAULT_AGENT_DISCOUNT_PERCENT = 10.0;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'rating' => 'float',
            'profile' => 'array',
            'working_days' => 'array',
            'agent_discount_percent' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Справочная информация о СТО для карточки ЗР (все роли):
     * фактический адрес и контакты, без коммерческих условий.
     *
     * @return array{name: string, address: string, contact: string, phone: string, email: string, workday: string}
     */
    public function referenceDetails(): array
    {
        $organization = $this->organization;
        $start = substr((string) ($this->workday_start ?? ''), 0, 5);
        $end = substr((string) ($this->workday_end ?? ''), 0, 5);

        return [
            'name'    => (string) $this->name,
            'address' => (string) (($organization?->actual_address ?? '') ?: $this->address),
            'contact' => (string) $this->contact,
            'phone'   => (string) ($organization?->phone ?? ''),
            'email'   => (string) ($organization?->email ?? ''),
            'workday' => $start !== '' && $end !== '' ? $start . '–' . $end : '',
        ];
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
