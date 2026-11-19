<?php

declare(strict_types=1);

namespace App\DTO;

use App\Models\RepairRequest;
use App\Models\User;

final class RequestEventDto
{
    public function __construct(
        public readonly int $requestId,
        public readonly int $userId,
        public readonly string $actorLabel,
        public readonly int $stage,
        public readonly int $revision,
        public readonly string $action,
        public readonly string $comment = '',
        public readonly array $details = [],
    ) {}

    public static function fromRequest(
        RepairRequest $request,
        UserDto $userDto,
        string $action,
        string $comment = '',
        array $details = []
    ): self {
        return new self(
            requestId: $request->id,
            userId: $userDto->userId,
            actorLabel: $userDto->role->label(),
            stage: $request->stage->value,
            revision: $request->revision,
            action: $action,
            comment: $comment,
            details: $details,
        );
    }
}