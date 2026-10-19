<?php

declare(strict_types=1);

namespace App\Enums;

enum AttachmentMime: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Mp4 = 'video/mp4';
    case Webm = 'video/webm';
    case Quicktime = 'video/quicktime';
    case Pdf = 'application/pdf';

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Mp4 => 'mp4',
            self::Webm => 'webm',
            self::Quicktime => 'mov',
            self::Pdf => 'pdf',
        };
    }

    public function isImage(): bool
    {
        return in_array($this, [self::Jpeg, self::Png, self::Webp], true);
    }

    public function isVideo(): bool
    {
        return in_array($this, [self::Mp4, self::Webm, self::Quicktime], true);
    }

    public function isMedia(): bool
    {
        return $this->isImage() || $this->isVideo();
    }

    public function isPdf(): bool
    {
        return $this === self::Pdf;
    }

    /**
     * @return array<int, self>
     */
    public static function media(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $mime): bool => $mime->isMedia(),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
