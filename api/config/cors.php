<?php

declare(strict_types=1);

return [
    'paths' => ['api/*'],
    'allowed_methods' => [
        'GET',
        'POST',
        'PATCH',
        'DELETE',
        'OPTIONS'
    ],
    'allowed_origins' => explode(',', env('FRONTEND_ORIGINS', '*')),
    'allowed_origins_patterns' => [],
    // Для Bearer-аутентификации обязателен Authorization.
    // X-CSRF-Token можно убрать, если CSRF больше не используется.
    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'Authorization'
    ],
    'exposed_headers' => [
        'Retry-After'
    ],
    'max_age' => 0,
    // Для Bearer-токенов cookie не передаются — false.
    // Если фронт шлёт credentials: 'include' — должно быть true,
    // но тогда allowed_origins не может быть ['*'].
    'supports_credentials' => false,
];
