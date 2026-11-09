<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RepairRequest;
use App\Services\Edo\EdoClosingDocumentsService;
use Illuminate\Console\Command;
use App\Models\EdoDocument;
use Illuminate\Support\Str;

class EdoTestClosingCommand extends Command
{
    protected $signature = 'edo:test-closing {number : Номер заявки (например, ZR-000007)} {--validate : Только сгенерировать без отправки}';
    protected $description = 'Тестирование генерации закрывающих документов (УПД, Счета)';

    public function handle(EdoClosingDocumentsService $service): int
    {
        $number = $this->argument('number');

        $this->info("Поиск заявки $number...");
        $repairRequest = RepairRequest::where('number', $number)->first();

        if (!$repairRequest) {
            $this->error("Заявка {$number} не найдена.");
            return self::FAILURE;
        }

        // Создаем тестовую запись в edo_documents (если её нет)
        $document = EdoDocument::firstOrCreate(
            ['number' => $repairRequest->number . '-УПД'],
            [
                'uuid' => Str::uuid(),
                'tenant_id' => $repairRequest->tenant_id,
                'organization_id' => $repairRequest->customer_organization_id,
                'document_type' => 'upd_dop',
                'provider' => 'sbis',
                'document_date' => now()->toDateString(),
                'status' => 'draft',
                'number' => $repairRequest->number . '-УПД',
            ]
        );

        $this->info("Запуск генерации...");

        try {
            $service->generateAndSendUpd($repairRequest, $document);
            $this->info("Успех! Проверьте storage/logs/edo-*.log");
        } catch (\Throwable $e) {
            $this->error("Ошибка: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
