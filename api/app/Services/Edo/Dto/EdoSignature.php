<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

final readonly class EdoSignature
{
    public function __construct(
        public string $content,         // base64 PKCS#7
        public ?string $uuid = null,
        public ?string $signerCode = null,
    ) {
    }
}