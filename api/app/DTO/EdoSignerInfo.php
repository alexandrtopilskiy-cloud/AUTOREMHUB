<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EdoSignerInfo
{
    public function __construct(
        public string $inn,
        public string $organizationName,
        public string $lastName,
        public string $firstName,
        public ?string $middleName,
        public string $title,
        public string $authority,
        public int $authorityScope,
        public int $condition,
    ) {
    }
}
