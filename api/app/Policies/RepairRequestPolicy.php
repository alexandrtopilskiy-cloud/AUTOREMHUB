<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\User;

final class RepairRequestPolicy
{
    /**
     * Может ли пользователь просматривать список заявок?
     * (В вашем случае — это все роли, но с фильтрацией в самом запросе)
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Может ли пользователь просматривать конкретную заявку?
     */
    public function view(User $user, RepairRequest $request): bool
    {
        // УК (Manager) видит все заявки своего тенанта
        if ($user->role === UserRole::Manager) {
            return $request->tenant_id === $user->tenantId();
        }

        // Заказчик видит заявки своей организации
        if ($user->role === UserRole::Customer) {
            return $request->customer_organization_id === $user->organization_id;
        }

        // СТО видит заявки, где она назначена
        if ($user->role === UserRole::Station) {
            $stationOrganizationId = $request->stationRecord?->organization_id;

            return $stationOrganizationId === $user->organization_id;
        }

        return false;
    }

    /**
     * Может ли пользователь создавать заявку?
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    /**
     * Может ли пользователь обновлять заявку?
     */
    public function update(User $user, RepairRequest $request): bool
    {
        // Здесь можно добавить проверку на этап, но лучше оставить это в Workflow
        return $this->view($user, $request);
    }

    /**
     * Может ли пользователь удалять черновик?
     */
    public function delete(User $user, RepairRequest $request): bool
    {
        return $request->stage === RepairStage::Draft
            && $request->created_by === $user->id;
    }
}
