<?php
declare(strict_types = 1);

return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql', 'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'sto'), 'username' => env('DB_USERNAME', 'sto_api'),
            'password' => env('DB_PASSWORD', ''), 'charset' => 'utf8',
            'prefix' => '', 'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => 'UTC',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
