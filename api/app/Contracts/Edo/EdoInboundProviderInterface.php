<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\DTO\EdoSignature;

interface EdoInboundProviderInterface
{
    public function acceptDocument(string $externalId, EdoSignature $ticket): void;

    public function rejectDocument(string $externalId, string $comment): void;
}
