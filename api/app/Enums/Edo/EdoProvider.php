<?php

declare(strict_types=1);

namespace App\Enums\Edo;

enum EdoProvider: string
{
    case None = 'none';
    case SberKorus = 'sberkorus';
    case Diadoc = 'diadoc';
    case Sbis = 'sbis';
    case Taxcom = 'taxcom';
    case EdoRu = 'edoru';

    public function label(): string
    {
        return match ($this) {
            self::None      => 'ЭДО отключён',
            self::SberKorus => 'СберКорус',
            self::Diadoc    => 'Контур.Диадок',
            self::Sbis      => 'СБИС',
            self::Taxcom    => 'Такском',
            self::EdoRu     => 'ЭДО.ру',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}