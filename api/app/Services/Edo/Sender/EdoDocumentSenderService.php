<?php

declare(strict_types=1);

namespace App\Services\Edo\Sender;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoStructuredRequest;
use App\Services\Edo\EdoXmlValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\EdoDocument;

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

        if (config('edo.mock')) {
            $logger->info("Sender [MOCK]: Генерация PDF из XML и сохранение.");
            $this->handleMockPdf($dto);
        } else {
            $logger->info("Sender: Отправка в провайдер (реальный режим).");
            $result = $provider->createStructuredDocument($dto);

            $document->update([
                'external_id' => $result->externalId,
                'status'      => $result->status->value ?? 'draft', // Обычно 'draft'
            ]);

            $logger->info("Sender: Документ обновлен в БД", [
                'external_id' => $result->externalId,
                'status'      => $result->status->value
            ]);
        }
    }

    private function handleMockPdf(EdoStructuredRequest $dto): void
    {
        $logger = Log::channel('edo');

        // Предполагаем, что в DTO лежит file_content (PDF)
        $pdfContent = base64_decode($dto->meta['file_content']);
        $originalName = $dto->meta['file_name'] ?? 'document.pdf';
        $uniquePrefix = Str::uuid()->toString();
        $fileName = $uniquePrefix . '_' . $originalName;

        $disk = 'local';
        $objectKey = 'edo/mock/' . $dto->meta['repair_request_id'] . '_' . $fileName;

        Storage::disk($disk)->put($objectKey, $pdfContent);

        // ... Логика получения request_status_period_id и создания Attachment ...
        // (тут ваш код из saveMockAttachment)
    }
}
