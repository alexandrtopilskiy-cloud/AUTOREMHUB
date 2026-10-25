<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Enums\Edo\EdoDocumentType;
use App\Events\RepairRequests\VehicleAccepted;
use App\Services\Edo\EdoAcceptanceActService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class SendAcceptanceActToEdo implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;

    public function __construct(private readonly EdoAcceptanceActService $edoService) {}

    public function handle(VehicleAccepted $event): void
    {
        $existing = $event->request->edoDocument(EdoDocumentType::AcceptanceAct);

        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoService->send($event->request, $event->act);
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка отправки Акта приёма-передачи', [
                'request_id' => $event->request->id,
                'act_id' => $event->act->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
