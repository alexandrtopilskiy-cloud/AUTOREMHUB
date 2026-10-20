<?php

declare(strict_types=1);

namespace App\Enums\Edo;

enum EdoAcceptanceResultCode: int
{
    case AcceptedNoDisaccord = 1;
    case AcceptedDisaccord   = 2;
    case NotAccepted         = 3;

    public function label(): string
    {
        return match ($this) {
            self::AcceptedNoDisaccord => 'Принято без расхождений',
            self::AcceptedDisaccord   => 'Принято с расхождениями',
            self::NotAccepted         => 'Не принято',
        };
    }
}
