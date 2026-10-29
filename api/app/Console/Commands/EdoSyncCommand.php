<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Edo\EdoSyncService;
use Illuminate\Console\Command;

final class EdoSyncCommand extends Command
{
    protected $signature = 'edo:sync';
    protected $description = 'Синхронизация статусов документов с оператором ЭДО';

    public function handle(EdoSyncService $syncService): int
    {
        $this->info('Запуск синхронизации...');

        try {
            $syncService->sync();
            $this->info('Синхронизация завершена.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Ошибка: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
