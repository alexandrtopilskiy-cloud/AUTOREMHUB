<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\Edo\Drivers\Diadoc\DiadocClientFactory;
use App\Services\Edo\EdoClosingDocumentsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class TestEdoClosingDocuments extends Command
{
    protected $signature = 'edo:test-closing
                            {request? : Номер или UUID заявки}
                            {--validate : Проверить документы через CanPostMessage}';

    protected $description = 'Тестирует генерацию закрывающих документов для заявки';

    public function handle(
        EdoClosingDocumentsService $service,
        DiadocClientFactory $clientFactory,
    ): int {
        $key = $this->argument('request');

        if (!$key) {
            $this->error('Передайте номер заявки');
            return self::FAILURE;
        }

        $query = RepairRequest::query();
        if (Str::isUuid($key)) {
            $query->where('uuid', $key);
        } else {
            $query->where('number', $key);
        }
        $request = $query->first();

        if (!$request) {
            $this->error('Заявка не найдена');
            return self::FAILURE;
        }

        if (!$request->workOrder) {
            $this->error("У заявки {$request->number} нет заказ-наряда (WorkOrder)");
            return self::FAILURE;
        }

        $this->info("Заявка: {$request->number}");
        $this->info("Статус: {$request->stage->label()}");
        $this->info("Сумма для Заказчика: {$request->totalForCustomer()} ₽");
        $this->info("Сумма для СТО: {$request->totalForStation()} ₽");
        $this->info("Агентское вознаграждение: {$request->agentFee()} ₽");
        $this->info("Генерация документов...");

        try {
            $service->generate($request);
            $this->info('✅ Документы успешно сгенерированы!');
        } catch (\Throwable $e) {
            $this->error('❌ Ошибка генерации: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function checkDocument(EdoDocument $document, DiadocClientFactory $clientFactory): void
    {
        $this->info("--- Документ: {$document->number} ({$document->document_type}) ---");

        if (config('edo.mock')) {
            $this->warn("⚠️ EDO_MOCK=true, CanPostMessage пропущен.");
            return;
        }

        try {
            [$typeNamedId, $function, $version] = $this->mapMetadata($document->document_type);

            $prototype = [
                'FromBoxId' => $document->organization->edo_client_id,
                'ToBoxId' => $document->counterparty?->edo_client_id,
                'DocumentPrototypes' => [
                    [
                        'TypeNamedId' => $typeNamedId,
                        'Function' => $function,
                        'Version' => $version,
                    ],
                ],
            ];

            $client = $clientFactory->make($document->organization);
            $accessToken = $client->getAccessToken($document->organization);

            $result = $client->post('/CanPostMessage', $prototype, $accessToken);

            if (empty($result['Errors'])) {
                $this->info("✅ CanPostMessage: OK");
            } else {
                foreach ($result['Errors'] as $error) {
                    $this->error("❌ CanPostMessage: " . ($error['Message'] ?? 'Unknown error'));
                }
            }
        } catch (\Throwable $e) {
            $this->error("❌ CanPostMessage: " . $e->getMessage());
        }
    }

    private function mapMetadata(string $type): array
    {
        return match ($type) {
            EdoDocumentType::UpdSfDop->value => ['UniversalTransferDocument', 'СЧФДОП', 'utd970_05_03_01'],
            EdoDocumentType::Invoice->value => ['XmlProformaInvoice', '0', 'proformainvoice29_05_01_01'],
            EdoDocumentType::NonFormalized->value => ['nonformalized', 'default', '1.0'],
            default => throw new \RuntimeException("Неизвестный тип: {$type}"),
        };
    }
}
