<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\EdoRu;

use App\Models\Organization;

final class EdoRuClientFactory
{
    public function make(Organization $organization): EdoRuClient
    {
        if ($organization->edo_provider !== 'edoru') {
            throw new \InvalidArgumentException("Организация не на ЭДО.Поток: {$organization->edo_provider}");
        }

        if (!$organization->edo_client_id) {
            throw new \InvalidArgumentException("У организации {$organization->id} не заполнен edo_client_id");
        }

        $config = config('edo.providers.edoru');

        return EdoRuClient::fromConfig(array_merge($config, [
            'client_id' => $organization->edo_client_id,
            'password' => $organization->edo_password, // если хранится в БД
        ]));
    }
}
