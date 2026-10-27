<?php

declare(strict_types=1);

namespace App\Console\Commands\Edo;

use App\Jobs\SyncEdoDocumentJob;
use App\Models\EdoDocument;
use Illuminate\Console\Command;

final class SyncEdoStatusesCommand extends Command
{
    protected $signature = 'edo:sync-status
                            {--chunk=100 : Размер порции выборки}
                            {--minutes=15 : Сколько минут считать документ stale}';

    protected $description = 'Ставит в очередь синк статусов stale ЭДО-документов';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $minutes = (int) $this->option('minutes');

        $dispatched = 0;

        EdoDocument::stale($minutes)
        ->orderBy('id')
            ->chunkById($chunk, function ($documents) use (&$dispatched): void {
            /** @var EdoDocument $document */
            foreach ($documents as $document) {
                SyncEdoDocumentJob::dispatch($document->id);
                $dispatched++;
            }
        });

        $this->info(sprintf('Queued: %d', $dispatched));

        return self::SUCCESS;
    }
}
