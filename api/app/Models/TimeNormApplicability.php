<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Управляемое значение применимости нормы времени.
 *
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $normalized_name
 * @property bool $is_system
 * @property bool $active
 */
final class TimeNormApplicability extends Entity
{
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'active'    => 'boolean',
        ];
    }
}
