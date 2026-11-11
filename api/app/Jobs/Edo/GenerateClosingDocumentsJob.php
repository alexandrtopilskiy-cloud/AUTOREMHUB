<?php

declare(strict_types=1);

namespace App\Jobs\Edo;

use App\Models\RepairRequest;
use App\Services\Edo\EdoClosingDocumentsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateClosingDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly RepairRequest $req,
    public readonly bool $validateOnly = false
    ) {}

    public function handle(EdoClosingDocumentsService $service): void
    {
        $logger = Log::channel('edo');
        $logger->info("Job: Старт генерации закрывающих документов для заявки {$this->req->number}");

        try {
            $service->generateAllClosingDocuments($this->req, $this->validateOnly);
            $logger->info("Job: Успешно завершена для заявки {$this->req->number}");
        } catch (\Throwable $e) {
            $logger->error("Job: Ошибка генерации для {$this->req->number}", [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
