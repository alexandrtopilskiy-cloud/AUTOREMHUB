<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EdoSignature
{
    public function __construct(
        public string $content,
        public ?string $uuid = null,
        public ?string $signerCode = null,
        public string $certificateFingerprint = '',
    ) {}
}