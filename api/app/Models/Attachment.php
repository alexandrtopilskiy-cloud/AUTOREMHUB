<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;

/**
 * @property int $size
 * @property AttachmentMime $mime_type
 * @property AttachmentPurpose $purpose
 */
final class Attachment extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size'      => 'integer',
            'mime_type' => AttachmentMime::class,
            'purpose'   => AttachmentPurpose::class,
        ];
    }
}
