<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Enums\Edo\EdoDocumentType;
use App\Events\RepairRequests\WorkOrderCompleted;
use App\Enums\Edo\EdoProvider;
use App\Services\Edo\EdoActService;
use App\Services\Edo\EdoDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Exceptions\EdoNotConnectedException;
use Illuminate\Support\Facades\Log;

final class SendWorkOrderActToEdo implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;

    public function __construct(
        private readonly EdoActService $edoService,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function handle(WorkOrderCompleted $event): void
    {
        // СБИС: УПД СТО → УК уходит в комплекте закрывающих документов ЗР
        // (UPD-…/1, черновик → подпись в «Документы ЭДО»), отдельный Акт не нужен.
        $station = $event->request->stationRecord?->organization;
        if ($station !== null && !(bool) config('edo.mock', false) && $this->dispatcher->providerFor($station) === EdoProvider::Sbis) {
            Log::channel('edo')->info("ЭДО: Акт выполненных работ по ЗР {$event->request->number} не отправляется — в СБИС уходит УПД из закрывающих документов");

            return;
        }

        // Проверка на дубликат (например, если заявку вернули и снова завершили)
        $existing = $event->request->edoDocument(EdoDocumentType::ActWorks);
        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoService->sendAct($event->request, $event->workOrder);
        } catch (EdoNotConnectedException $e) {
            // Организация не подключена к оператору — повторы не помогут, пропускаем.
            Log::channel('edo')->warning('ЭДО: УПД/Акт выполненных работ не отправлен — ' . $e->getMessage());

            return;
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
