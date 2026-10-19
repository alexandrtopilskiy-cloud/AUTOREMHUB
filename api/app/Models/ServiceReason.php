<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $is_maintenance
 * @property bool $requires_diagnosis
 * @property bool $active
 */
final class ServiceReason extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_maintenance'     => 'boolean',
            'requires_diagnosis' => 'boolean',
            'active'             => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
