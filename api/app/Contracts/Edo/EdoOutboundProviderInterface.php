<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;

interface EdoOutboundProviderInterface
{
    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto;

    public function addAttachment(string $externalId, EdoFile $file): void;

    public function signDocument(string $externalId, EdoSignature $signature): void;

    public function sendDocument(string $externalId): void;
}
