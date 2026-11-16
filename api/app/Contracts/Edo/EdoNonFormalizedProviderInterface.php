<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\DTO\EdoDocumentDto;
use App\DTO\EdoStructuredRequest;

/**
 * Оператор ЭДО, принимающий неформализованные документы (PDF):
 * заказ-наряд, счёт на оплату.
 */
interface EdoNonFormalizedProviderInterface
{
    /**
     * Создаёт (или обновляет, если externalId уже есть у оператора) документ
     * с PDF-вложением из meta['file_name'] / meta['file_content'] (base64).
     */
    public function createNonFormalizedDocument(EdoStructuredRequest $request): EdoDocumentDto;
}
