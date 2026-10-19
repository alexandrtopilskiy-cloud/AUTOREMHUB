<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\RequestEvent;
use App\Models\User;
use Illuminate\Support\Collection;

final class ChangeRegistryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function records(User $user): array
    {
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Реестр изменений доступен только УК',
            );
        }

        $catalog = CatalogChangeEvent::query()
            ->with('actor')
            ->where('tenant_id', $user->tenantId())
            ->latest('occurred_at')
            ->limit(500)
            ->get()
            ->map(static fn (CatalogChangeEvent $event): array => [
                'id'          => 'catalog-' . $event->uuid,
                'kind'        => 'changeRegistry',
                'scope'       => 'Справочник: ' . $event->catalog_kind,
                'record'      => $event->record_label,
                'action'      => $event->action,
                'actor'       => $event->actor?->display_name
                    ?? $event->actor?->login
                    ?? 'Консоль',
                'changedFields' => implode(', ', $event->changed_fields ?? []),
                'description' => $event->record_label,
                'occurredAt'  => $event->occurred_at->toISOString(),
                'permissions' => ['canEdit' => false],
            ]);

        $requests = RequestEvent::query()
            ->with(['actor', 'repairRequest:id,uuid,number,tenant_id'])
            ->whereHas(
                'repairRequest',
                fn ($query) => $query->where('tenant_id', $user->tenantId()),
            )
            ->latest('occurred_at')
            ->limit(500)
            ->get()
            ->map(static fn (RequestEvent $event): array => [
                'id'          => 'request-' . $event->uuid,
                'kind'        => 'changeRegistry',
                'scope'       => 'Заявка на ремонт',
                'record'      => $event->repairRequest->number,
                'action'      => $event->action,
                'actor'       => $event->actor?->display_name
                    ?? $event->actor_label,
                'changedFields' => '',
                'description' => $event->comment,
                'occurredAt'  => $event->occurred_at->toISOString(),
                'permissions' => ['canEdit' => false],
            ]);

        return Collection::make([...$catalog, ...$requests])
            ->sortByDesc('occurredAt')
            ->take(500)
            ->values()
            ->all();
    }
}
