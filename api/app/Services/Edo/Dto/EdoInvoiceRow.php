<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

final readonly class EdoInvoiceRow
{
    public function __construct(
        public string $name,
        public string $unit,        // "018" | "796"
        public string $amount,      // строка, точка-разделитель
        public string $price,       // с НДС
        public string $netSum,      // без НДС
        public string $vatSum,      // НДС
        public string $totalSum,    // с НДС
        public string $vatType,     // "20%" | "без НДС"
    ) {
    }
}