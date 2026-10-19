<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationKind: string
{
    case Customer = 'customer';
    case Station = 'station';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Заказчик',
            self::Station => 'СТО-партнёр',
            self::Manager => 'Управляющая компания',
        };
    }

    public function userRole(): UserRole
    {
        return UserRole::from($this->value);
    }

    public function matchesUserRole(UserRole $role): bool
    {
        return $this->userRole() === $role;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
