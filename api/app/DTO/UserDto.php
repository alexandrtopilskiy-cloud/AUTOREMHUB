<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\UserRole;
use App\Models\RepairRequest;

final readonly class UserDto
{
    /**
     * @param callable(RepairRequest): array<string, mixed> $change
     */
    public function __construct(
        public int $userId,
        public int $tenantId,
        public int $organizationId,
        public UserRole $role,
    ) {}
}