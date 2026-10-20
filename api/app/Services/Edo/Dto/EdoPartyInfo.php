<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

final readonly class EdoPartyInfo
{
    public function __construct(
        public string $name,
        public string $inn,
        public string $kpp,
        public ?string $address = null,
        public ?string $regionCode = null,
        public ?string $ediCode = null,   // ← добавили
    ) {}
}