<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Enums\Edo\EdoDocumentType;
use App\Events\RepairRequests\ReadyForIssue;
use App\Services\Edo\EdoAgentReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class SendAgentReportToEdo implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;

    public function __construct(private readonly EdoAgentReportService $edoService) {}

    public function handle(ReadyForIssue $event): void
    {
        $existing = $event->request->edoDocument(EdoDocumentType::NonFormalized);
        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoService->sendAgentReport($event->request);
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка отправки Отчета агента', [
                'request_id' => $event->request->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
