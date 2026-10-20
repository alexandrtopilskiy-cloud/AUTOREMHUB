<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EdoDocument;
use App\Services\Edo\EdoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncEdoDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * @var int
     */
    public int $timeout = 60;

    public function __construct(
        public readonly int $documentId,
    ) {
    }

    public function handle(EdoService $service): void
    {
        $document = EdoDocument::query()->find($this->documentId);

        if ($document === null) {
            // Документ удалили, пока job ждала в очереди — не ошибка.
            return;
        }

        if (! $document->hasExternalId()) {
            // Нечего синкать — документ ещё не зарегистрирован в ЭДО.
            return;
        }

        $service->syncStatus($document);
    }

    /**
     * После последней неудачной попытки — пишем в лог, job падает в failed_jobs.
     */
    public function failed(Throwable $e): void
    {
        Log::error('SyncEdoDocumentJob failed', [
            'document_id' => $this->documentId,
            'error'       => $e->getMessage(),
            'class'       => $e::class,
        ]);
    }
}
