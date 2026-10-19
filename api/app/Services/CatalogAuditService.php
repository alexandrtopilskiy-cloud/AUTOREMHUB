<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Models\CatalogChangeEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

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
        if ($before === null) {
            return array_values(array_diff(
                array_keys($after),
                self::IGNORED_FIELDS,
            ));
        }

        $keys = array_diff(
            array_unique([...array_keys($before), ...array_keys($after)]),
            self::IGNORED_FIELDS,
        );

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null),
        ));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function label(array $attributes): string
    {
        if (isset($attributes['last_name'], $attributes['first_name'])) {
            return trim(implode(' ', array_filter([
                $attributes['last_name'],
                $attributes['first_name'],
                $attributes['middle_name'] ?? null,
            ])));
        }

        return (string) (
            $attributes['name']
            ?? $attributes['vehicle']
            ?? $attributes['article']
            ?? $attributes['payload']['vehicle']
            ?? ''
        );
    }
}
