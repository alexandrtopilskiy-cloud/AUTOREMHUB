<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepairStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property RepairStage $stage
 * @property CarbonImmutable $entered_at
 * @property CarbonImmutable|null $exited_at
 */
final class RequestStatusPeriod extends Entity
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage'      => RepairStage::class,
            'entered_at' => 'immutable_datetime',
            'exited_at'  => 'immutable_datetime',
        ];
    }
}
