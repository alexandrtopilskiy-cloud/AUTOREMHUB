<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DomainInput as Input;

/**
 * Форматы Excel-шаблонов для импортируемых справочников ЛК УК.
 *
 * Ключ массива — поле API, значение — точный заголовок столбца шаблона.
 */
final class CatalogImportSchema
{
    /** @var array<string, string> */
    private const ORGANIZATION = [
        'name'                 => 'Наименование',
        'shortName'            => 'Краткое наименование',
        'inn'                  => 'ИНН',
        'kpp'                  => 'КПП',
        'ogrn'                 => 'ОГРН',
        'legalAddress'         => 'Юридический адрес',
        'actualAddress'        => 'Фактический адрес',
        'postalAddress'        => 'Почтовый адрес',
        'directorName'         => 'Руководитель',
        'contact'              => 'Контакт',
        'phone'                => 'Телефон',
        'email'                => 'Электронная почта',
        'bankName'             => 'Банк',
        'bik'                  => 'БИК',
        'settlementAccount'    => 'Расчётный счёт',
        'correspondentAccount' => 'Корреспондентский счёт',
        'taxRegime'            => 'Система налогообложения',
        'vatRate'              => 'Ставка НДС, %',
    ];

    /** @var array<string, string> */
    private const STATION_PROFILE = [
        'address'                     => 'Адрес СТО',
        'workdayStart'                => 'Начало работы',
        'workdayEnd'                  => 'Окончание работы',
        'workingDays'                 => 'Рабочие дни через ;',
        'mechanicalBays'              => 'Механические посты',
        'lifts'                       => 'Подъёмники',
        'mechanicsCount'              => 'Механики',
        'electriciansCount'           => 'Электрики',
        'electricianBays'             => 'Посты автоэлектрики',
        'alignmentBays'               => 'Посты сход-развала',
        'engineBays'                  => 'Моторные посты',
        'paintBooths'                 => 'Покрасочные камеры',
        'frameRacks'                  => 'Стапели',
        'bodyworkBays'                => 'Кузовные посты',
        'engineRepair'                => 'Ремонт двигателя',
        'automaticTransmissionRepair' => 'Ремонт АКПП',
        'manualTransmissionRepair'    => 'Ремонт МКПП',
        'steeringRackRepair'          => 'Ремонт рулевых реек',
        'bodyRepair'                  => 'Кузовной ремонт',
        'brakeDiscSkimming'           => 'Проточка дисков',
        'wheelAlignment'              => 'Сход-развал',
        'tireService'                 => 'Шиномонтаж',
        'tireStorage'                 => 'Хранение шин',
        'evacuation'                  => 'Эвакуация',
        'warrantyService'             => 'Гарантийное обслуживание',
        'reception'                   => 'Зона приёмки',
        'waitingArea'                 => 'Зона ожидания',
        'photoVideoFixation'          => 'Фото- и видеофиксация',
        'itAccounting'                => 'ИТ-учёт',
        'insuranceFleetExperience'    => 'Опыт со страховыми и автопарками',
        'trainingCertificates'        => 'Сертификаты обучения',
        'passengerCars'               => 'Легковые ТС',
        'lcv'                         => 'Лёгкий коммерческий транспорт',
        'trucks'                      => 'Грузовые ТС',
        'slaDescription'              => 'SLA',
        'vehicleRestrictions'         => 'Ограничения по ТС',
        'customerAmenities'           => 'Удобства для заказчика',
        'uniqueServices'              => 'Уникальные услуги',
        'yearsOperating'              => 'Лет работы',
        'active'                      => 'Активна',
    ];

    /** @var array<string, array{name: string, headers: array<string, string>}> */
    private const SCHEMAS = [
        'vehicles' => [
            'name' => 'Справочник ТС Заказчиков',
            'headers' => [
                'organization'          => 'Наименование заказчика',
                'make'                  => 'Марка',
                'model'                 => 'Модель',
                'bodyCode'              => 'Поколение / кузов',
                'modelYearFrom' => 'Год выпуска с',
                'modelYearTo' => 'Год выпуска до',
                'engineType' => 'Тип двигателя',
                'engineIndex' => 'Индекс двигателя',
                'horsepower' => 'Мощность, л.с.',
                'engineVolumeLiters' => 'Объём двигателя, л',
                'transmission' => 'Вид трансмиссии',
                'driveType' => 'Тип привода',
                'vin'                   => 'VIN',
                'plate'                 => 'Госномер',
                'year'                  => 'Год выпуска',
                'baselineServiceMileage' => 'Предыдущий пробег',
                'baselineServiceAt'     => 'Дата предыдущего пробега',
                'laborHourRate'         => 'Общий нормо/час, ₽',
                'laborHourRateOfficial' => 'Официальный нормо/час, ₽',
                'laborHourRateOther' => 'Прочий нормо/час, ₽',
                'laborRateCoefficient'  => 'Поправочный коэффициент',
                'contactPhones'         => 'Телефоны контактных лиц через ;',
                'driverPhones'          => 'Телефоны водителей через ;',
            ],
        ],
        'vehicleModels' => [
            'name' => 'Справочник ТС (марки, модели)',
            'headers' => [
                'make'          => 'Марка',
                'model'         => 'Модель',
                'modelYearFrom' => 'Модельный год от',
                'modelYearTo'   => 'Модельный год до',
                'engineIndex'   => 'Индекс двигателя',
                'bodyCode'      => 'Поколение / кузов',
                'horsepower'    => 'Мощность, л.с.',
                'engineVolumeLiters' => 'Объём двигателя, л',
                'transmission'  => 'Вид трансмиссии',
                'driveType'     => 'Тип привода',
                'engineType'    => 'Тип двигателя',
            ],
        ],
        'drivers' => [
            'name' => 'Водители',
            'headers' => [
                'organization' => 'Наименование заказчика',
                'lastName'     => 'Фамилия',
                'firstName'    => 'Имя',
                'middleName'   => 'Отчество',
                'phone'        => 'Сотовый телефон',
            ],
        ],
        'contactPersons' => [
            'name' => 'Контактные лица',
            'headers' => [
                'organization' => 'Наименование заказчика',
                'lastName'     => 'Фамилия',
                'firstName'    => 'Имя',
                'middleName'   => 'Отчество',
                'phone'        => 'Сотовый телефон',
            ],
        ],
        'workTypes' => [
            'name' => 'Справочник Работ',
            'headers' => [
                'name'          => 'Наименование работы',
                'group'         => 'Группа',
                'isMaintenance' => 'ТО',
                'active'        => 'Активна',
                'applicability' => 'Применимость',
                'vehicleMake'   => 'Марка ТС',
                'vehicleModel'  => 'Модель ТС',
                'hours'         => 'Норма времени, ч',
                'source'        => 'Источник нормы',
            ],
        ],
        'normApplicabilities' => [
            'name' => 'Применимости норм',
            'headers' => [
                'name'   => 'Применимость',
                'active' => 'Активна',
            ],
        ],
        'parts' => [
            'name' => 'Справочник запчастей',
            'headers' => [
                'name'         => 'Наименование',
                'article'      => 'Артикул',
                'manufacturer' => 'Производитель',
                'supplier' => 'Поставщик',
                'purchasePrice' => 'Цена закупа',
                'salePrice' => 'Цена реализации',
                'unit'         => 'Единица измерения',
                'active'       => 'Активна',
            ],
        ],
        'customers' => [
            'name'    => 'Справочник Заказчики',
            'headers' => self::ORGANIZATION,
        ],
        'stations' => [
            'name'    => 'Справочник СТО',
            'headers' => self::ORGANIZATION + self::STATION_PROFILE,
        ],
        'managers' => [
            'name'    => 'Справочник УК',
            'headers' => self::ORGANIZATION,
        ],
        'users' => [
            'name' => 'Справочник Пользователи',
            'headers' => [
                'lastName'        => 'Фамилия',
                'firstName'       => 'Имя',
                'middleName'      => 'Отчество',
                'email'           => 'Электронная почта',
                'login'           => 'Логин',
                'password'        => 'Пароль',
                'phone'           => 'Мобильный телефон',
                'organization'    => 'Принадлежность к организации',
                'registeredAt'    => 'Дата первичной регистрации',
                'accessRevokedAt' => 'Дата отзыва доступа',
                'active'          => 'Активен',
                'accessRights'    => 'Права доступа через ;',
            ],
        ],
    ];

    /** @return array<int, string> */
    public static function kinds(): array
    {
        return array_keys(self::SCHEMAS);
    }

    public static function supports(string $kind): bool
    {
        return isset(self::SCHEMAS[$kind]);
    }

    public static function name(string $kind): string
    {
        return self::schema($kind)['name'];
    }

    /** @return array<string, string> */
    public static function headers(string $kind): array
    {
        return self::schema($kind)['headers'];
    }

    public static function filename(string $kind): string
    {
        return 'template-' . preg_replace('/[^a-z0-9-]/', '-', mb_strtolower($kind)) . '.xlsx';
    }

    /** @return array{name: string, headers: array<string, string>} */
    private static function schema(string $kind): array
    {
        if (!isset(self::SCHEMAS[$kind])) {
            Input::fail('Импорт для этого справочника не поддерживается');
        }

        return self::SCHEMAS[$kind];
    }
}
