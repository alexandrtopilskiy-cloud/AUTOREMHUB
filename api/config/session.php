<?php
return [
    'driver' => 'database', 'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false, 'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'connection' => 'pgsql', 'table' => 'sessions', 'store' => null,
    'lottery' => [2, 100], 'cookie' => env('SESSION_COOKIE', 'sto_session'),
    'path' => '/', 'domain' => env('SESSION_DOMAIN'),
    'secure' => (bool) env('SESSION_SECURE_COOKIE', true),
    'http_only' => true, 'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => false,
];
