<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Enums\Edo\EdoDocumentType;
use App\Events\RepairRequests\InvoiceIssued;
use App\Services\Edo\EdoInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class SendInvoiceToEdo implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;

    public function __construct(private readonly EdoInvoiceService $edoService) {}

    public function handle(InvoiceIssued $event): void
    {
        // Проверка на дубликат
        $existing = $event->request->edoDocument(EdoDocumentType::Invoice);
        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoService->sendInvoice($event->request, $event->invoice);
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка отправки Счёта-фактуры на вознаграждение', [
                'request_id' => $event->request->id,
                'invoice_id' => $event->invoice->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
