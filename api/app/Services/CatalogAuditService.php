<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Models\CatalogChangeEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class CatalogAuditService
{
    private const IGNORED_FIELDS = [
        'id',
        'uuid',
        'tenant_id',
        'created_at',
        'updated_at',
    ];

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     */
    public function record(
        int $tenantId,
        ?User $actor,
        CatalogKind|string $kind,
        Model $record,
        string $action,
        ?array $before,
        array $after,
    ): CatalogChangeEvent
    {
        return CatalogChangeEvent::query()->create([
            'tenant_id'      => $tenantId,
            'actor_id'       => $actor?->id,
            'catalog_kind'   => $kind instanceof CatalogKind ? $kind->value : $kind,
            'record_uuid'    => $record->getAttribute('uuid'),
            'record_label'   => $this->label($after),
            'action'         => $action,
            'before_payload' => $before,
            'after_payload'  => $after,
            'changed_fields' => $this->changedFields($before, $after),
            'occurred_at'    => now(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     *
     * @return array<int, string>
     */
    private function changedFields(?array $before, array $after): array
    {
        $afterFiltered = Arr::except($after, self::IGNORED_FIELDS);

        if ($before === null) {
            return array_values($afterFiltered);
        }

        $beforeFiltered = Arr::except($before, self::IGNORED_FIELDS);

        // Ищем ключи, которые есть в after, но отличаются от before (или отсутствуют в before)
        $changedKeys = [];
        foreach ($afterFiltered as $key => $value) {
            // Если ключа нет в before или значение отличается (с учетом null)
            if (!array_key_exists($key, $beforeFiltered) || $beforeFiltered[$key] !== $value) {
                $changedKeys[] = $key;
            }
        }

        // На случай, если в before были ключи, которых нет в after (удаленные поля)
        foreach ($beforeFiltered as $key => $value) {
            if (!array_key_exists($key, $afterFiltered)) {
                $changedKeys[] = $key;
            }
        }

        return array_values($changedKeys);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function label(array $attributes): string
    {
        // 1. Блок с ФИО (если есть фамилия и имя)
        if (isset($attributes['last_name'], $attributes['first_name'])) {
            return collect([
                $attributes['last_name'],
                $attributes['first_name'],
                $attributes['middle_name'] ?? null,
            ])->filter()->implode(' ');
        }

        // 2. Fallback (если ФИО нет)
        $label  = $attributes['name'] ?? $attributes['vehicle'] ?? $attributes['article'] ?? data_get($attributes, 'payload.vehicle');

        return (string) ($label ?? '');
    }
}
