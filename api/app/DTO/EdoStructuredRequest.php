<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\Edo\EdoDocumentType;

final class EdoStructuredRequest
{
    /**
     * @param array<int, array{name: string, quantity: string, price: string, sum: string, vatRate: string, vatSum: string}> $items
     */
    public function __construct(
        public readonly EdoDocumentType $type,
        public readonly string $number,
        public readonly \DateTimeImmutable $date,
        public readonly EdoOrganizationDto $sender,
        public readonly EdoOrganizationDto $receiver,
        public readonly array $items,
        public readonly string $totalSum,
        public readonly string $vatSum,
        public readonly bool $vatIncluded,
        public readonly array $meta = [],
    ) {
    }
}