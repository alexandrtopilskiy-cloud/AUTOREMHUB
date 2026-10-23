<?php

declare(strict_types=1);

namespace App\DTO;

use App\DTO\EdoPartyInfo;

final readonly class EdoInvoicePayload
{
    /**
     * @param array<int, EdoInvoiceRow> $rows
     */
    public function __construct(
        public EdoPartyInfo $seller,
        public EdoPartyInfo $buyer,
        public array $rows,
        public string $currencyCode = '643',
        public string $formatVersion = '5.03',
        public string $formatCode = '1115131',
        public ?string $idContract = null,
    ) {
    }
}