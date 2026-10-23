<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\Edo\EdoDocumentFunction;

final readonly class EdoUcdPayload
{
    /**
     * @param EdoUcdRow[] $rows
     */
    public function __construct(
        public EdoDocumentFunction $documentFunction,
        public string $formatVersion,
        public string $formatCode,
        public string $currencyCode,
        public EdoPartyInfo $seller,
        public EdoPartyInfo $buyer,
        public array $rows,
        public ?string $invoiceNumber = null,
        public ?\DateTimeImmutable $invoiceDate = null,
        public ?string $invoiceRevisionNumber = null,
        public ?\DateTimeImmutable $invoiceRevisionDate = null,
        public ?string $revisionNumber = null,
        public ?\DateTimeImmutable $revisionDate = null,
    ) {
    }
}
