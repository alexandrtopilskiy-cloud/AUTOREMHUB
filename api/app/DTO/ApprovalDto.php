<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;
use App\Models\RepairRequest;
use App\Models\RequestEvent;
use App\Models\User;

final class ApprovalDto
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public readonly int $requestId,
        public readonly int $eventId,
        public readonly int $actorId,
        public readonly RepairStage $stage,
        public readonly ApprovalDecision $decision,
        public readonly string $comment,
        public readonly int $estimateVersion,
        public readonly array $snapshot,
    ) {}

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function fromRequest(
        RepairRequest $request,
        int $eventId,
        int $userId,
        RepairStage $stage,
        ApprovalDecision $decision,
        string $comment,
        array $snapshot
    ): self {
        return new self(
            requestId: $request->id,
            eventId: $eventId,
            actorId: $userId,
            stage: $stage,
            decision: $decision,
            comment: $comment,
            estimateVersion: $request->estimate_version,
            snapshot: $snapshot,
        );
    }
}
