<?php

declare(strict_types=1);

namespace App\Enums;

enum TransitionAction: string
{
    case Next = 'next';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Next => 'Передано',
            self::Return => 'Возврат',
        };
    }

    public function isNext(): bool
    {
        return $this === self::Next;
    }

    public function isReturn(): bool
    {
        return $this === self::Return;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
