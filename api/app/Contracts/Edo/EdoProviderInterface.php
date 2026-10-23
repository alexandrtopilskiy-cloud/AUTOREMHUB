<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\Enums\Edo\EdoProvider as EdoProviderEnum;

interface EdoProviderInterface
{
    public function provider(): EdoProviderEnum;
}
