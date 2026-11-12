<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\DTO\EdoDocumentDto;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;

interface EdoOutboundProviderInterface
{
    /**
     * Создает формализованный документ (УПД, СЧФ) из XML.
     */
    public function createStructuredDocument(EdoStructuredRequest $dto): EdoDocumentDto;

    /**
     * Создает неформализованный документ (Заказ-наряд, Акт) из PDF.
     */
    public function createNonFormalizedDocument(EdoStructuredRequest $request): EdoDocumentDto;

    /**
     * Подписывает документ.
     */
    public function signDocument(string $externalId, EdoSignature $signature): void;

    /**
     * Отправляет документ контрагенту.
     */
    public function sendDocument(string $externalId): void;
}