<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
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

    public function organizationKind(): OrganizationKind
    {
        return OrganizationKind::from($this->value);
    }

    public function isCustomer(): bool
    {
        return $this === self::Customer;
    }

    public function isStation(): bool
    {
        return $this === self::Station;
    }

    public function isManager(): bool
    {
        return $this === self::Manager;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
