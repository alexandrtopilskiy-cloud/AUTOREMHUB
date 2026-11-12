<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EdoPartyInfo
{
    public function __construct(
        public string $name,
        public string $inn,
        public string $kpp,
        public ?string $address,
        public ?string $regionCode,
        public ?string $ediCode,
        public bool $isIndividual = false,
        public ?string $ogrnip = null,
    ) {}
}