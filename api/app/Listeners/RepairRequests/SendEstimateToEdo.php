<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Enums\Edo\EdoDocumentType;
use App\Events\RepairRequests\DiagnosticReportSaved;
use App\Services\Edo\EdoEstimateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Exceptions\EdoNotConnectedException;
use Illuminate\Support\Facades\Log;

final class SendEstimateToEdo implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;

    public function __construct(private readonly EdoEstimateService $edoService) {}

    public function handle(DiagnosticReportSaved $event): void
    {
        // Проверка на дубликат
        $existing = $event->request->edoDocument(EdoDocumentType::WorkOrder);
        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoService->sendEstimate($event->request);
        } catch (EdoNotConnectedException $e) {
            // Организация не подключена к оператору — повторы не помогут, пропускаем.
            Log::channel('edo')->warning('ЭДО: предварительный заказ-наряд не отправлен — ' . $e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка отправки предварительного заказ-наряда', [
                'request_id' => $event->request->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
