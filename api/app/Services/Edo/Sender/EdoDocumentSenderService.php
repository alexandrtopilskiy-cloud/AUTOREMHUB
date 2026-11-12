<?php

declare(strict_types=1);

namespace App\Services\Edo\Sender;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoStructuredRequest;
use App\Services\Edo\EdoXmlValidator;
use Illuminate\Support\Facades\Log;
use App\Models\EdoDocument;
use App\Enums\Edo\EdoDocumentType;

final class EdoDocumentSenderService
{
    public function __construct(
        private readonly EdoXmlValidator $validator
    ) {}

    public function process(
        EdoDocument $document,
        EdoStructuredRequest $dto,
        EdoOutboundProviderInterface $provider,
        bool $validateOnly
    ): void {
        $logger = Log::channel('edo');
        $xmlContent = $dto->meta['xml_content'];

        try {
            $this->validator->validate($xmlContent, 'ON_NSCHFDOPPR_1_998_01_05_01_01.xsd');
            $logger->info("Sender: XML прошел валидацию.");
        } catch (\Throwable $e) {
            $logger->error("Sender: Ошибка валидации XML", ['message' => $e->getMessage()]);
            throw $e;
        }

        if ($validateOnly) {
            $logger->info("Sender: Режим --validate. Отправка пропущена.");
            return;
        }

        $logger->info("Sender: Отправка в провайдер (реальный режим).");

        // Выбираем метод в зависимости от типа документа
        if ($dto->type === EdoDocumentType::NonFormalized) {
            // Для Заказ-наряда (PDF)
            $result = $provider->createNonFormalizedDocument($dto);
        } else {
            // Для УПД (XML)
            $result = $provider->createStructuredDocument($dto);
        }

        $document->update([
            'external_id' => $result->externalId,
            'status'      => $result->status->value ?? 'draft',
        ]);
    }
}
