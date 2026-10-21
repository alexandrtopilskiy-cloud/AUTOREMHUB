<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $recommendation_id
 * @property string $kind
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed> $display
 * @property string $actor_label
 * @property int|null $actor_id
 * @property int|null $target_request_id
 * @property CarbonImmutable|null $moved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @property-read Recommendation $recommendation
 */
final class RecommendationItem extends Entity
{
    protected function casts(): array
    {
        return [
            'snapshot'   => 'array',
            'display'    => 'array',
            'moved_at'   => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Recommendation, $this> */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }
}
