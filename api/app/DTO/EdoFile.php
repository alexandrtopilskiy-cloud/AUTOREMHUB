<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EdoFile
{
    public function __construct(
        public readonly string $content,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly bool $isBase64 = true,
    ) {
    }

    public static function fromBase64(string $filename, string $mimeType, string $base64): self
    {
        return new self($base64, $filename, $mimeType, true);
    }

    public static function fromBinary(string $filename, string $mimeType, string $binary): self
    {
        return new self(base64_encode($binary), $filename, $mimeType, true);
    }

    public function toBinary(): string
    {
        return base64_decode($this->content, true) ?: '';
    }

    public function raw(): string
    {
        return $this->isBase64 ? base64_decode($this->content, true) : $this->content;
    }
}