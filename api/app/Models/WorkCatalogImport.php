<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property array<string, mixed> $summary
 * @property array<int, mixed> $errors
 * @property CarbonImmutable|null $applied_at
 */
final class WorkCatalogImport extends Entity
{
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'errors' => 'array',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
