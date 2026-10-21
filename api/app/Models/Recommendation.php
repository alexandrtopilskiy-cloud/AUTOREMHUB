<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $number
 * @property int $tenant_id
 * @property int $vehicle_id
 * @property int $customer_organization_id
 * @property int|null $source_request_id
 * @property int|null $created_by
 * @property string $vin
 * @property int $revision
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @property-read Vehicle|null $vehicle
 * @property-read Collection<int, RecommendationItem> $items
 */
final class Recommendation extends Entity
{
    protected function casts(): array
    {
        return [
            'revision'     => 'integer',
            'created_at'   => 'immutable_datetime',
            'updated_at'   => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return HasMany<RecommendationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RecommendationItem::class)->orderBy('id');
    }

    /** @return Builder<self> */
    public static function forUser(User $user): Builder
    {
        $query = self::query()->where('tenant_id', $user->tenantId());
        if ($user->role === UserRole::Customer) {
            $query->where('customer_organization_id', $user->organization_id);
        } elseif ($user->role === UserRole::Station) {
            $query->whereIn('vehicle_id', RepairRequest::forUser($user)->select('vehicle_id')->whereNotNull('vehicle_id'));
        }
        return $query;
    }
}
