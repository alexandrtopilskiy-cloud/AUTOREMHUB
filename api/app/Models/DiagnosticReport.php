<?php

declare(strict_types=1);

namespace App\Models;

/**
 * @property array<int, string> $attachment_ids
 * @property int $mileage
 */
final class DiagnosticReport extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachment_ids' => 'array',
            'mileage'        => 'integer',
        ];
    }
}