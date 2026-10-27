<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\DTO\EdoOrganizationDto;

interface EdoOrganizationProviderInterface
{
    public function getSelfOrganization(): EdoOrganizationDto;

    public function findOrganization(string $inn, ?string $kpp = null): ?EdoOrganizationDto;
}
