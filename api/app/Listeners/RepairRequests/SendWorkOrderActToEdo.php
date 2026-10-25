<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Enums\Edo\EdoDocumentType;
use App\Events\RepairRequests\WorkOrderCompleted;
use App\Services\Edo\EdoActService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class SendWorkOrderActToEdo implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;

    public function __construct(private readonly EdoActService $edoService) {}

    public function handle(WorkOrderCompleted $event): void
    {
        // Проверка на дубликат (например, если заявку вернули и снова завершили)
        $existing = $event->request->edoDocument(EdoDocumentType::ActWorks);
        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoService->sendAct($event->request, $event->workOrder);
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка отправки УПД/Акта выполненных работ', [
                'request_id' => $event->request->id,
                'work_order_id' => $event->workOrder->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
