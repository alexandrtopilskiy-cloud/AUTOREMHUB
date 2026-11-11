<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EdoOrganizationDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $inn,
        public ?string $kpp = '',
        public ?string $ogrn = '',
        public ?string $ediCode = null,
        public ?string $operatorCode = null,

        // --- Поля для СБИС ---
        public ?string $login = null,           // edo_client_id
        public ?string $password = null,        // расшифрованный edo_refresh_token
        public ?string $token = null,           // edo_token
        public ?\DateTimeImmutable $tokenExpiresAt = null, // edo_token_expires_at
    ) {}

    public function hasEdiCode(): bool
    {
        return $this->ediCode !== null && trim($this->ediCode) !== '';
    }
}