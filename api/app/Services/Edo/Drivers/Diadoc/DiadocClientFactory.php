<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Diadoc;

use App\Enums\Edo\EdoProvider;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

final class DiadocClientFactory
{
    public function make(Organization $organization): DiadocClient
    {
        if ($organization->edo_provider !== EdoProvider::Diadoc) {
            throw new \InvalidArgumentException("Организация не на Диадоке: {$organization->edo_provider}");
        }

        $config = config('edo.providers.diadoc');

        Log::info(json_encode($config));
        return new DiadocClient(
            baseUrl: $config['base_url'] ?? 'https://diadoc-api.kontur.ru',
            clientId: $config['client_id'],
            clientSecret: $config['client_secret'],
            timeout: (int) ($config['http']['timeout'] ?? 30),
            connectTimeout: (int) ($config['http']['connect_timeout'] ?? 10),
        );
    }
}
