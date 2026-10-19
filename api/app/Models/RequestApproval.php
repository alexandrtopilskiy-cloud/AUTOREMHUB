<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;

/**
 * @property array<string, mixed> $snapshot
 * @property RepairStage $stage
 * @property int $estimate_version
 * @property ApprovalDecision $decision
 */
final class RequestApproval extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot'         => 'array',
            'stage'            => RepairStage::class,
            'estimate_version' => 'integer',
            'decision'         => ApprovalDecision::class,
        ];
    }
}
