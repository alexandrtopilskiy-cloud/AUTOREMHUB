<?php

declare(strict_types=1);

namespace App\DTO\Pdf;

final readonly class PdfPartyDto
{
    public function __construct(
        public string $displayName,
        public string $director,
    ) {}
}
