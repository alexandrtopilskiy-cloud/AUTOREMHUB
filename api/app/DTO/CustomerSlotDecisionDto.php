<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\ApprovalDecision;
use App\Http\Requests\CustomerSlotDecisionRequest;

final class CustomerSlotDecisionDto
{
    public function __construct(
        public readonly string $requestKey,
        public readonly int $revision,
        public readonly ApprovalDecision $decision,
        public readonly string $comment = '',
        public readonly ?string $slot = null,
    ) {}

    public static function fromRequest(CustomerSlotDecisionRequest $request, string $id): self
    {
        return new self(
            requestKey: $id,
            revision: (int) $request->validated('revision'),
            decision: ApprovalDecision::from($request->validated('decision')),
            comment: (string) $request->validated('comment', ''),
            slot: $request->validated('slot'),
        );
    }
}
