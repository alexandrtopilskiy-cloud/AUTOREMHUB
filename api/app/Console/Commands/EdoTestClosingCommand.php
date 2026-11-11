<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RepairRequest;
use App\Services\Edo\EdoClosingDocumentsService;
use Illuminate\Console\Command;

class EdoTestClosingCommand extends Command
{
    protected $signature = 'edo:test-closing
                            {number : Номер заявки (например, ZR-000007)}
                            {--validate : Только сгенерировать и валидировать XML, без отправки}';
    protected $description = 'Тестирование генерации закрывающих документов';

    public function handle(EdoClosingDocumentsService $service): int
    {
        $number = $this->argument('number');
        $validateOnly = (bool) $this->option('validate');

        $this->info("Поиск заявки {$number}...");
        $repairRequest = RepairRequest::query()
            ->where('number', $number)
            ->first();

        if (!$repairRequest) {
            $this->error("Заявка {$number} не найдена.");
            return self::FAILURE;
        }

        $this->info("Запуск генерации...");

        try {
            $service->generateAllClosingDocuments($repairRequest, $validateOnly);
            $this->info("Успех! Проверьте storage/logs/edo-*.log");
        } catch (\Throwable $e) {
            $this->error("Ошибка: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
