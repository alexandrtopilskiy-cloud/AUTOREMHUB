<?php

declare(strict_types=1);

namespace App\DTO\Pdf;

final readonly class PdfTotalsDto
{
    public function __construct(
        public string $amountFormatted,
        public string $vatFormatted,
    ) {}
}
