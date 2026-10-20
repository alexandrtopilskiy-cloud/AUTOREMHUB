<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

final readonly class EdoSyncResult
{
    /**
     * @param array<int, array<string, mixed>> $events
     */
    public function __construct(
        public array $events,
        public int $lastEventId,
    ) {
    }
}