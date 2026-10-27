<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EdoAccountInfoDto
{
    public function __construct(
        public string $login,
        public bool $isBlocked,
        public ?string $tariffName,
        public ?string $tariffExpiredAt,
        public int $availableDocuments,
        public int $consumedDocuments,
        public ?float $balance,
    ) {
    }
}