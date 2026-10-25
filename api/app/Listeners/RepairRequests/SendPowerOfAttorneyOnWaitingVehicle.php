<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Enums\Edo\EdoDocumentType;
use App\Events\RepairRequests\RepairRequestWaitingVehicle;
use App\Services\Edo\EdoPowerOfAttorneyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class SendPowerOfAttorneyOnWaitingVehicle implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(
        private readonly EdoPowerOfAttorneyService $edoPowerOfAttorneyService
    ) {
    }

    public function handle(RepairRequestWaitingVehicle $event): void
    {
        $request = $event->request;

        // Проверяем, не создавали ли мы уже документ
        $existing = $request->edoDocument(EdoDocumentType::PowerOfAttorney);

        // Если документ есть и он не в ошибочном статусе — пропускаем
        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoPowerOfAttorneyService->send($request);
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка отправки доверенности', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Пробрасываем для ретрая очереди
        }
    }
}