<?php

declare(strict_types=1);

namespace App\Models;

/**
 * @property array<string, mixed> $snapshot
 */
final class WorkOrderVersion extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }
}