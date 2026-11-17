<?php

declare(strict_types=1);

namespace App\Traits;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentStatus;
use App\Services\Edo\Drivers\Sbis\SbisProvider;

trait SendsEdoDocuments
{
    /** Документ только записан у оператора черновиком (подпись — из «Документы ЭДО»). */
    protected bool $edoDraftOnly = false;

    /**
     * Выполняет полный цикл отправки документа: создание -> вложение -> подпись -> отправка.
     * СБИС: документ (PDF) записывается черновиком, подпись УКЕП и отправка —
     * пользователем из раздела «Документы ЭДО», как у закрывающих документов.
     */
    protected function dispatchToProvider(
        EdoOutboundProviderInterface $provider,
        EdoStructuredRequest $request,
        EdoFile $file,
        EdoSignature $signature
    ): EdoDocumentDto {
        $this->edoDraftOnly = false;

        if ($provider instanceof SbisProvider) {
            $this->edoDraftOnly = true;

            return $provider->createNonFormalizedDocument($request);
        }

        // 1. Создаем структурированный документ
        $documentDto = $provider->createStructuredDocument($request);

        // 2. Добавляем вложение (PDF)
        $provider->addAttachment($documentDto->externalId, $file);

        // 3. Подписываем
        $provider->signDocument($documentDto->externalId, $signature);

        // 4. Отправляем
        $provider->sendDocument($documentDto->externalId);

        return $documentDto;
    }

    /** Статус записи ЭДО после dispatchToProvider. */
    protected function dispatchedStatus(EdoDocumentDto $result): EdoDocumentStatus
    {
        return $this->edoDraftOnly ? $result->status : EdoDocumentStatus::Sended;
    }

    /** @return array<string, mixed> отметки meta для записи ЭДО после dispatchToProvider */
    protected function dispatchedMeta(EdoDocumentDto $result): array
    {
        return $this->edoDraftOnly
            ? ['state' => 'written', 'mock' => false, 'error' => null, 'url' => $result->raw['url'] ?? null]
            : [];
    }
}
