<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Recommendation extends Entity
{
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function vehicle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecommendationItem::class)->orderBy('id');
    }

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
