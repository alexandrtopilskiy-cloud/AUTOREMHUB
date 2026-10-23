<?php

declare(strict_types=1);

use App\Enums\Edo\EdoProvider;
use App\Services\Edo\Drivers\EdoRu\EdoRuProvider;
use App\Services\Edo\Drivers\Taxcom\TaxcomProvider;

return [
    'default' => env('EDO_PROVIDER', EdoProvider::None->value),
    'providers' => [
        /*
        |----------------------------------------------------------------------
        | ЭДО.ру (ЭДО.Поток) — ИС «ЭДО.Поток», API v1
        |----------------------------------------------------------------------
        |
        | Документация: https://edo.ru/integration/api-edopotok (v5.2 от 03.03.2026)
        |
        | Кодировка запросов/ответов — Windows-1251. Клиент конвертирует
        | в UTF-8 на входе/выходе.
        |
        | auth_mode:
        |   - auth-global  — POST /clients/auth-global (clientId + password)
        |   - auth-with-ds — КЭП: GET/POST /clients/auth-with-ds (fingerprint)
        |
        | В sandbox-контуре обычно доступен https://dev-dev.edo.ru/api/edo/v1
        | (см. пример в доке, п. 2.2.16). Прод: https://lk.edo.ru/api/edo/v1.
        |
        */
        EdoProvider::EdoRu->value => [
            'class'    => EdoRuProvider::class,
            'base_url' => env('EDO_EDORU_BASE_URL', 'https://lk.edo.ru/api/edo/v1'),
            'auth_mode'=> env('EDO_EDORU_AUTH_MODE', 'auth-global'),

            // auth-global
            'client_id'=> env('EDO_EDORU_CLIENT_ID'),
            'password' => env('EDO_EDORU_PASSWORD'),

            // auth-with-ds
            'fingerprint' => env('EDO_EDORU_FINGERPRINT'),

            'encoding' => 'windows-1251',

            'http' => [
                'timeout'         => (int) env('EDO_EDORU_TIMEOUT', 30),
                'connect_timeout' => (int) env('EDO_EDORU_CONNECT_TIMEOUT', 10),
            ],
        ],

        EdoProvider::Taxcom->value => [
            'class' => TaxcomProvider::class,
            'base_url' => env('TAXCOM_BASE_URL', 'https://api.taxcom.ru/v1.0'),
            'integrator_id' => env('TAXCOM_INTEGRATOR_ID'),
            'http' => [
                'timeout' => 30,
                'connect_timeout' => 10,
            ],
        ],
        EdoProvider::Diadoc->value => [
            'base_url' => env('DIADOC_BASE_URL', 'https://diadoc-api.kontur.ru'),
            'client_id' => env('DIADOC_CLIENT_ID'),
            'client_secret' => env('DIADOC_CLIENT_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Входящий webhook (задел на будущее)
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'enabled' => (bool) env('EDO_WEBHOOK_ENABLED', false),
        'secret'  => env('EDO_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Наценка УК по умолчанию
    |--------------------------------------------------------------------------
    |
    | Применяется, если у организации-УК не заполнено поле markup_percent.
    |
    */
    'manager_markup_percent' => (float) env('EDO_MANAGER_MARKUP_PERCENT', 10.0),
];