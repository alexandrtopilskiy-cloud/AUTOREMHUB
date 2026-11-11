<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Enums\Edo\EdoProvider;
use App\Models\Organization;

final class SbisClientFactory
{
    public function make(Organization $organization): SbisClient
    {
        if ($organization->edo_provider !== EdoProvider::Sbis) {
            throw new \InvalidArgumentException("Организация не на СБИС: {$organization->edo_provider}");
        }

        $config = config('edo.providers.sbis');

        return new SbisClient(
            baseUrl: $config['base_url'],
            authUrl: $config['auth_url'],
            organization: $organization,
            login: $organization->edo_client_id,
            password: $organization->edo_token,
            sessionId: $organization->edo_token,
            timeout: (int) ($config['http']['timeout']),
            connectTimeout: (int) ($config['http']['connect_timeout']),
        );
    }
}
