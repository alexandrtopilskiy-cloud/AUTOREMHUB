<?php

declare(strict_types=1);

namespace App\Traits;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;

trait SendsEdoDocuments
{
    /**
     * Выполняет полный цикл отправки документа: создание -> вложение -> подпись -> отправка.
     */
    protected function dispatchToProvider(
        EdoOutboundProviderInterface $provider,
        EdoStructuredRequest $request,
        EdoFile $file,
        EdoSignature $signature
    ): EdoDocumentDto {
        // 1. Создаем структурированный документ
        $documentDto = $provider->createStructuredDocument($request);

        // 2. Добавляем вложение (PDF)
        $provider->addAttachment($documentDto->documentId, $file);

        // 3. Подписываем
        $provider->signDocument($documentDto->documentId, $signature);

        // 4. Отправляем
        $provider->sendDocument($documentDto->documentId);

        return $documentDto;
    }
}
