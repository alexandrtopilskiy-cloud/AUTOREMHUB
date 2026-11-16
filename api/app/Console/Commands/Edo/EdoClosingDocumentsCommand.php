<?php

declare(strict_types=1);

namespace App\Console\Commands\Edo;

use App\Jobs\Edo\GenerateClosingDocumentsJob;
use App\Models\RepairRequest;
use Illuminate\Console\Command;

/**
 * Ручной запуск формирования закрывающих документов ЗР и отправки в ЭДО
 * (то же, что делает джоба на этапе 7). Пример:
 *   php artisan edo:closing-documents ZR-000123 --validate
 */
final class EdoClosingDocumentsCommand extends Command
{
    protected $signature = 'edo:closing-documents
                            {request : Номер ЗР (ZR-000123) или id}
                            {--validate : Только сформировать и проверить УПД по XSD, без записи в ЭДО}
                            {--queue : Поставить в очередь вместо выполнения сразу}';

    protected $description = 'Формирует ЗН, Счёт, УПД по ЗР (этап 7) и записывает их в ЭДО черновиками';

    public function handle(): int
    {
        $key = (string) $this->argument('request');
        $digits = (int) preg_replace('/\D+/', '', $key);
        $request = RepairRequest::query()->find($digits);

        if ($request === null) {
            $this->error("ЗР {$key} не найдена");

            return self::FAILURE;
        }

        $job = new GenerateClosingDocumentsJob($request->id, null, (bool) $this->option('validate'));

        if ($this->option('queue')) {
            dispatch($job)->onQueue((string) config('edo.queue', 'edo'));
            $this->info("ЗР {$request->number}: поставлено в очередь " . config('edo.queue', 'edo'));

            return self::SUCCESS;
        }

        dispatch_sync($job);
        $state = $request->refresh()->data['closingDocumentsStatus'] ?? null;
        $this->info("ЗР {$request->number}: " . json_encode($state, JSON_UNESCAPED_UNICODE));
        $this->line('Подробности — storage/logs/edo-*.log');

        return self::SUCCESS;
    }
}
