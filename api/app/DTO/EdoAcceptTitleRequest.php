<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\Edo\EdoAcceptanceResultCode;
use App\DTO\EdoSignerInfo;

final readonly class EdoAcceptTitleRequest
{
    public function __construct(
        public string $externalId,
        public EdoAcceptanceResultCode $resultCode,
        public string $dealSummary,
        public \DateTimeImmutable $date,
        public EdoSignerInfo $signer,
    ) {
    }
}
