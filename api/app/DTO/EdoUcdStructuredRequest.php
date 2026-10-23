<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\Edo\EdoDocumentType;

final readonly class EdoUcdStructuredRequest
{
    public function __construct(
        public EdoDocumentType $type,
        public string $receiverEdiCode,
        public string $number,
        public \DateTimeImmutable $date,
        public EdoUcdPayload $payload,
        public ?EdoSignerDto $signer = null,
    ) {}
}
