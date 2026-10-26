<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\TransitionAction;
use App\Http\Requests\TransitionRequest;

final class TransitionDto
{
    /**
     * @param array<int, string> $attachmentIds
     */
    public function __construct(
        public readonly string $requestKey,
        public readonly int $revision,
        public readonly TransitionAction $action,
        public readonly string $comment = '',
        public readonly bool $confirmDeclinedStation = false,
        public readonly array $attachmentIds = [],
    ) {}

    public static function fromRequest(TransitionRequest $request, string $id, TransitionAction $action): self
    {
        return new self(
            requestKey: $id,
            revision: (int) $request->validated('revision'),
            action: $action,
            comment: (string) $request->validated('comment', ''),
            confirmDeclinedStation: (bool) $request->validated('confirmDeclinedStation', false),
            attachmentIds: (array) $request->validated('attachmentIds', []),
        );
    }
}
