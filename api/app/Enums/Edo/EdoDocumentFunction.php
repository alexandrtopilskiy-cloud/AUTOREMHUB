<?php

declare(strict_types=1);

namespace App\Enums\Edo;

enum EdoDocumentFunction: string
{
    case UcdCsf    = 'UCD_CSF';
    case UcdCsfDic = 'UCD_CSF_DIC';
    case UcdDic    = 'UCD_DIC';

    public function label(): string
    {
        return match ($this) {
            self::UcdCsf    => 'УКД. Корректировочный счёт-фактура',
            self::UcdCsfDic => 'УКД. Корректировочный счёт-фактура и документ об изменении стоимости',
            self::UcdDic    => 'УКД. Документ об изменении стоимости',
        };
    }
}
