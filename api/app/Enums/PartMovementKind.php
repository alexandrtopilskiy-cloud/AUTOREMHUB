<?php

declare(strict_types=1);

namespace App\Enums;

enum PartMovementKind: string
{
    case Return = 'return';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Return => 'Возврат',
            self::Exchange => 'Обмен',
        };
    }

    public function isReturn(): bool
    {
        return $this === self::Return;
    }

    public function isExchange(): bool
    {
        return $this === self::Exchange;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
