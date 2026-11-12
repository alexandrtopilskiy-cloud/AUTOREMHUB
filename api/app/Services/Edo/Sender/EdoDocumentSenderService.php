<?php

declare(strict_types=1);

namespace App\Services\Edo\Sender;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Services\Edo\EdoXmlValidator;
use Illuminate\Support\Facades\Log;

final class EdoDocumentSenderService
{
    public function __construct(
        private readonly EdoXmlValidator $validator
    ) {}

    /**
     * Обрабатывает документ и возвращает результат (DTO) для обновления в БД.
     * Если режим --validate, возвращает null.
     */
    public function process(EdoStructuredRequest $dto, EdoOutboundProviderInterface $provider, bool $validateOnly): ?EdoDocumentDto
    {
        if ($dto->type === EdoDocumentType::NonFormalized) {
            return $this->processPdf($dto, $provider);
        }

        return $this->processXml($dto, $provider, $validateOnly);
    }

    /**
     * Обработка формализованных документов (УПД, СЧФ, УКД).
     */
    private function processXml(EdoStructuredRequest $dto, EdoOutboundProviderInterface $provider, bool $validateOnly): ?EdoDocumentDto
    {
        $logger = Log::channel('edo');
        $xmlContent = $dto->meta['xml_content'];

        // 1. Валидация XML
        try {
            $this->validator->validate($xmlContent, 'ON_NSCHFDOPPR_1_998_01_05_01_01.xsd');
            $logger->info("Sender [XML]: XML прошел валидацию.");
        } catch (\Throwable $e) {
            $logger->error("Sender [XML]: Ошибка валидации XML", ['message' => $e->getMessage()]);
            throw $e;
        }

        if ($validateOnly) {
            $logger->info("Sender [XML]: Режим --validate. Отправка пропущена.");
            return null;
        }

        // 2. Отправка
        return $provider->createStructuredDocument($dto);
    }

    /**
     * Обработка неформализованных документов (Заказ-наряд, Акт в PDF).
     */
    private function processPdf(EdoStructuredRequest $dto, EdoOutboundProviderInterface $provider): EdoDocumentDto
    {
        return $provider->createNonFormalizedDocument($dto);
    }
}
