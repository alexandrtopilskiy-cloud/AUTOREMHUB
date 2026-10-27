<?php

declare(strict_types=1);

namespace App\Enums\Edo;

enum UpdFunction: string
{
    case Sf    = 'СЧФ';
    case SfDop = 'СЧФДОП';
    case Dop   = 'ДОП';

    public function label(): string
    {
        return match ($this) {
            self::Sf    => 'Счёт-фактура',
            self::SfDop => 'Счёт-фактура и документ об отгрузке',
            self::Dop   => 'Документ об отгрузке',
        };
    }
}
