<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;

final readonly class EdoDocumentDto
{
    public function __construct(
        public string $externalId,
        public ?string $externalUuid,
        public EdoDocumentType $type,
        public EdoDocumentStatus $status,
        public string $number,
        public \DateTimeImmutable $date,
        public ?string $senderName = null,
        public ?string $receiverName = null,
        public ?EdoFile $content = null,
        public array $raw = [],
    ) {
    }
}