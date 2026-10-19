<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkOrderItemKind: string
{
    case Work = 'work';
    case Part = 'part';

    public function label(): string
    {
        return match ($this) {
            self::Work => 'Работа',
            self::Part => 'Запчасть',
        };
    }

    public function isWork(): bool
    {
        return $this === self::Work;
    }

    public function isPart(): bool
    {
        return $this === self::Part;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
