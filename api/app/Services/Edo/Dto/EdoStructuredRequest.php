<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

use App\Enums\Edo\EdoDocumentType;

final readonly class EdoStructuredRequest
{
    public function __construct(
        public EdoDocumentType $type,
        public string $receiverEdiCode,
        public string $number,
        public \DateTimeImmutable $date,
        public EdoInvoicePayload $payload,
        public ?EdoSignerDto $signer = null,
        public string $senderEdiCode = '',
    ) {}
}