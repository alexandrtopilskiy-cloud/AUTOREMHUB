<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PartMovementKind;

/**
 * @property string $quantity
 * @property array<int, string> $attachment_ids
 * @property PartMovementKind $kind
 */
final class PartMovement extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity'       => 'decimal:3',
            'attachment_ids' => 'array',
            'kind'           => PartMovementKind::class,
        ];
    }
}
