<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

final readonly class EdoFile
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $content,          // base64
    ) {
    }

    public static function fromBase64(string $filename, string $mimeType, string $base64): self
    {
        return new self($filename, $mimeType, $base64);
    }

    public static function fromBinary(string $filename, string $mimeType, string $binary): self
    {
        return new self($filename, $mimeType, base64_encode($binary));
    }

    public function toBinary(): string
    {
        return base64_decode($this->content, true) ?: '';
    }
}