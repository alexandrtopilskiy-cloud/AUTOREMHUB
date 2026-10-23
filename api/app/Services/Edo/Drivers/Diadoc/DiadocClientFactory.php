<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Diadoc;

use App\Models\Organization;

final class DiadocClientFactory
{
    public function make(Organization $organization): DiadocClient
    {
        if ($organization->edo_provider !== 'diadoc') {
            throw new \InvalidArgumentException("Организация не на Диадоке: {$organization->edo_provider}");
        }

        $config = config('edo.providers.diadoc');

        return new DiadocClient(
            baseUrl: $config['base_url'] ?? 'https://diadoc-api.kontur.ru',
            clientId: $config['client_id'],
            clientSecret: $config['client_secret'],
            accessToken: $organization->edo_token ?? $config['access_token'] ?? '',
        );
    }
}
