<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Неизменяемая запись журнала изменений справочников.
 *
 * @property int $tenant_id
 * @property int|null $actor_id
 * @property string $catalog_kind
 * @property string|null $record_uuid
 * @property string $record_label
 * @property string $action
 * @property array<string, mixed>|null $before_payload
 * @property array<string, mixed>|null $after_payload
 * @property array<int, string> $changed_fields
 * @property CarbonImmutable $occurred_at
 */
final class CatalogChangeEvent extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_payload' => 'array',
            'after_payload'  => 'array',
            'changed_fields' => 'array',
            'occurred_at'    => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
