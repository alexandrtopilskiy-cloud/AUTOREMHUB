<?php

declare(strict_types=1);

namespace App\Services;

final class WorkCatalogSchema
{
    /** @var array<string, string> */
    public const WORK_HEADERS = [
        'workCode' => 'Код работы',
        'groupCode' => 'Код группы',
        'group' => 'Группа',
        'name' => 'Наименование работы',
        'isMaintenance' => 'ТО',
        'active' => 'Активна',
        'sourceRow' => 'Исходная строка',
        'source' => 'Источник',
    ];

    /** @var array<string, string> */
    public const NORM_HEADERS = [
        'normId' => 'norm_id',
        'workCode' => 'Код работы',
        'hours' => 'Норма времени, ч',
        'vin' => 'VIN',
        'make' => 'Марка',
        'model' => 'Модель',
        'generation' => 'Поколение',
        'engine' => 'Индекс двигателя',
        'transmission' => 'Трансмиссия',
        'drive' => 'Привод',
        'modificationCode' => 'Код модификации',
        'active' => 'Активна',
        'source' => 'Источник',
    ];
}
