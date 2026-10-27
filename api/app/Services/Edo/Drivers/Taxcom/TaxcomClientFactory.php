<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Taxcom;

use App\Models\Organization;

final class TaxcomClientFactory
{
    public function make(Organization $organization): TaxcomClient
    {
        if ($organization->edo_provider !== 'taxcom') {
            throw new \InvalidArgumentException("Организация не на Такском: {$organization->edo_provider}");
        }

        if (!$organization->edo_client_id) {
            throw new \InvalidArgumentException("У организации {$organization->id} не заполнен edo_client_id (логин Такском)");
        }

        $config = config('edo.providers.taxcom', []);

        return TaxcomClient::fromConfig(array_merge($config, [
            'login' => $organization->edo_client_id,
            'password' => $organization->edo_password, // нужно поле в БД или Vault
        ]));
    }
}
