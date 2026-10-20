<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

final readonly class EdoOrganizationDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $inn,
        public ?string $kpp,
        public ?string $ogrn,
        public ?string $ediCode,
        public ?string $operatorCode = null,
    ) {
    }

    public function hasEdiCode(): bool
    {
        return $this->ediCode !== null && trim($this->ediCode) !== '';
    }
}