<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewContext: string
{
    case Initial = 'initial';
    case StationChanges = 'station_changes';
    case StationDeclined = 'station_declined';
    case CustomerSlot = 'customer_slot';
    case ManagerChanges = 'manager_changes';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Изначальный',
            self::StationChanges => 'СТО изменила смету',
            self::StationDeclined => 'СТО отказалась',
            self::CustomerSlot => 'Заказчик изменил время',
            self::ManagerChanges => 'УК изменила смету',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
