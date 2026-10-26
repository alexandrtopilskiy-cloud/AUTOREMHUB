<?php

declare(strict_types=1);

namespace App\DTO;

use App\Models\User;

final class NotificationDto
{
    /**
     * @param iterable<int, User> $recipients
     */
    public function __construct(
        public readonly iterable $recipients,
        public readonly int $requestId,
        public readonly int $eventId,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
    ) {}

    /**
     * Удобная фабрика для создания из моделей.
     * @param iterable<int, User> $recipients
     */
    public static function fromRequest(
        iterable $recipients,
        int $requestId,
        int $eventId,
        string $kind,
        string $title,
        string $message
    ): self {
        return new self(
            recipients: $recipients,
            requestId: $requestId,
            eventId: $eventId,
            kind: $kind,
            title: $title,
            message: $message,
        );
    }
}
