<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Изменение справочника ТС, предложенное Заказчиком.
 *
 * @property int $tenant_id
 * @property int $organization_id
 * @property int|null $vehicle_id
 * @property int $requested_by
 * @property int|null $reviewed_by
 * @property string $action
 * @property string $status
 * @property array<string, mixed> $payload
 * @property string $review_comment
 * @property CarbonImmutable|null $reviewed_at
 *
 * @property-read Organization $organization
 * @property-read Vehicle|null $vehicle
 * @property-read User $requester
 * @property-read User|null $reviewer
 */
final class VehicleChangeRequest extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
