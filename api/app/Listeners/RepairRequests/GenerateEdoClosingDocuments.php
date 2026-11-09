<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Events\RepairRequests\WorkOrderCompleted;
use App\Services\Edo\EdoClosingDocumentsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class GenerateEdoClosingDocuments implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(
        private readonly EdoClosingDocumentsService $edoService
    ) {}

    public function handle(WorkOrderCompleted $event): void
    {
        try {
            $this->edoService->generate($event->request);
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка генерации закрывающих документов', [
                'request_id' => $event->request->id,
                'work_order_id' => $event->workOrder->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
