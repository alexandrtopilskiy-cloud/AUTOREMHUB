<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Enums\Edo\EdoDocumentType;
use App\Events\Organizations\AgentContractRequested;
use App\Models\EdoDocument;
use App\Services\Edo\EdoAgentContractService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class SendAgentContractToEdo implements ShouldQueue
{
    public string $queue = 'edo';
    public int $tries = 5;

    public function __construct(private readonly EdoAgentContractService $edoService) {}

    public function handle(AgentContractRequested $event): void
    {
        $customer = $event->customer;

        // Проверяем, есть ли уже договор.
        // Для договоров связь может быть не через repair_request, а напрямую через организацию.
        $existing = EdoDocument::query()
        ->where('counterparty_id', $customer->id)
            ->where('document_type', EdoDocumentType::AgencyContract->value)
            ->latest('id')
            ->first();

        if ($existing && !in_array($existing->status, ['draft', 'rejected', 'unknown'], true)) {
            return;
        }

        try {
            $this->edoService->sendContract($customer);
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка отправки Агентского договора', [
                'organization_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
