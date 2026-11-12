<?php

declare(strict_types=1);

namespace App\DTO\Pdf;

final readonly class PdfRowDto
{
    public function __construct(
        public string $name,
        public string $quantityFormatted,
        public string $unit,
        public string $priceFormatted,
        public string $vatLabel,
        public string $amountFormatted,
    ) {}
}
