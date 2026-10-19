<?php

declare(strict_types=1);

namespace App\Enums;

enum PersonKind: string
{
    case Contact = 'contact';
    case Driver = 'driver';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Контактное лицо',
            self::Driver => 'Водитель',
        };
    }

    public function catalogKind(): CatalogKind
    {
        return match ($this) {
            self::Contact => CatalogKind::ContactPersons,
            self::Driver => CatalogKind::Drivers,
        };
    }

    public function isContact(): bool
    {
        return $this === self::Contact;
    }

    public function isDriver(): bool
    {
        return $this === self::Driver;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
