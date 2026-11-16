<?php

declare(strict_types=1);

namespace App\DTO\Pdf;

final readonly class PdfPartyDto
{
    public function __construct(
        public string $displayName,
        public string $director,
        public ?string $name = null,                   // Название для вывода в банковской шапке
        public ?string $inn = null,
        public ?string $kpp = null,
        public ?string $bik = null,
        public ?string $correspondent_account = null,
        public ?string $settlement_account = null,
    ) {}
}
