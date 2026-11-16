<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Models\Organization;

final class SbisClientFactory
{
    public function make(Organization $organization): SbisClient
    {
        $config = (array) config('edo.providers.sbis', []);

        return new SbisClient(
            baseUrl: (string) ($config['base_url'] ?? 'https://online.sbis.ru/service/?srv=1'),
            authUrl: (string) ($config['auth_url'] ?? 'https://online.sbis.ru/auth/service/'),
            organization: $organization,
            timeout: (int) ($config['http']['timeout'] ?? 30),
            connectTimeout: (int) ($config['http']['connect_timeout'] ?? 10),
        );
    }
}
