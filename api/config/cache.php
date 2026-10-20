<?php
declare(strict_types=1);

return [
    'default' => env('CACHE_STORE', 'database'),
    'stores' => [
        'database' => [
            'driver' => 'database',
            'connection' => 'pgsql',
            'table' => 'cache',
            'lock_connection' => 'pgsql',
            'lock_table' => 'cache_locks'
        ],
        'array' => [
            'driver' => 'array',
            'serialize' => false
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data')
        ],
    ],
    'prefix' => 'sto_api_',
];
