<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepairStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $details
 * @property RepairStage $stage
 * @property int $revision
 * @property CarbonImmutable $occurred_at
 */
final class RequestEvent extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details'     => 'array',
            'stage'       => RepairStage::class,
            'revision'    => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class);
    }
}
