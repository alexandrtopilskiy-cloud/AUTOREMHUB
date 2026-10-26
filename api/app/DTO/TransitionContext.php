<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;
use App\Enums\TransitionAction;
use App\Models\Attachment;

final class TransitionContext
{
    /**
     * @param array<int, Attachment> $files
     * @param array<int, string> $changes
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public readonly RepairStage $from,
        public readonly RepairStage $to,
        public readonly TransitionAction $action,
        public readonly ?ApprovalDecision $decision,
        public readonly string $comment,
        public readonly array $snapshot,
        public readonly array $files = [],
        public readonly array $changes = [],
        public readonly string $reviewContext = 'initial',
        public readonly bool $isReadyForIssue = false,
    ) {}
}
