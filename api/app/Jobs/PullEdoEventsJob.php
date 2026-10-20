<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Models\EdoDocument;
use App\Models\EdoProviderState;
use App\Services\Edo\EdoManager;
use App\Services\Edo\EdoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PullEdoEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly string $providerValue,
    ) {
    }

    public function handle(EdoManager $manager, EdoService $service): void
    {
        $providerEnum = EdoProviderEnum::from($this->providerValue);

        // Синкаем только активного провайдера. Если в .env поменяли —
        // старые job'ы в очереди молча выходят.
        if ($manager->provider()->provider() !== $providerEnum) {
            return;
        }

        $state = EdoProviderState::forProvider($providerEnum);
        $fromEventId = $state->last_event_id;

        $result = $manager->provider()->pullEvents($fromEventId, 100);

        if ($result->events === []) {
            $state->update(['last_pulled_at' => now()]);

            return;
        }

        $eventDocumentIds = [];
        foreach ($result->events as $event) {
            $documentId = $event['DocumentId'] ?? null;
            if ($documentId === null) {
                continue;
            }
            $eventDocumentIds[(string) $documentId] = true;
        }

        // Находим локальные документы по external_id и ставим им синк.
        $localIds = EdoDocument::query()
        ->where('provider', $providerEnum->value)
            ->whereIn('external_id', array_keys($eventDocumentIds))
            ->pluck('id');

        foreach ($localIds as $id) {
            SyncEdoDocumentJob::dispatch((int) $id);
        }

        $state->update([
            'last_event_id'  => $result->lastEventId,
            'last_pulled_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('PullEdoEventsJob failed', [
            'provider' => $this->providerValue,
            'error'    => $e->getMessage(),
            'class'    => $e::class,
        ]);
    }
}
