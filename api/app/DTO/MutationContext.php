<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\User;

final readonly class MutationContext
{
    /**
     * @param callable(RepairRequest): array<string, mixed> $change
     */
    public function __construct(
        public int $userId,
        public int $tenantId,
        public int $organizationId,
        public UserRole $userRole,
        public string $actorlabel,
        public string $requestKey,
        public int $revision,
        public string $action,
    ) {}

    /**
     * Фабрика для быстрого создания из модели User.
     * Это удобно вызывать в контроллерах.
     */
    public static function fromUser(
        User $user,
        string $requestKey,
        int $revision,
        string $action,
    ): self {
        return new self(
            userId: $user->id,
            tenantId: $user->tenantId(),
            organizationId: $user->organization_id,
            role: $user->role,
            requestKey: $requestKey,
            revision: $revision,
            action: $action,
        );
    }
}