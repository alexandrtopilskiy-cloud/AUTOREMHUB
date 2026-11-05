<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\UserRole;
use App\Models\User;

final readonly class UserDto
{
    public function __construct(
        public int $userId,
        public int $tenantId,
        public int $organizationId,
        public UserRole $role,
        public string $organizationName
    ) {}

    /**
     * Фабрика для создания DTO из Eloquent-модели.
     */
    public static function fromUserModel(User $user): self
    {
        return new self(
            id: $user->id,
            tenantId: $user->tenantId(),
            organizationId: $user->organization_id,
            role: $user->role,
            organizationName: $user->organization->name,
        );
    }
}
