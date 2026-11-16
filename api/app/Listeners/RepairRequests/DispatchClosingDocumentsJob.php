<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Events\RepairRequests\ClosingDocumentsRequested;
use App\Jobs\Edo\GenerateClosingDocumentsJob;

final class DispatchClosingDocumentsJob
{
    public function handle(ClosingDocumentsRequested $event): void
    {
        // В очередь: формирование PDF и обращения к ЭДО не тормозят переход по маршруту.
        GenerateClosingDocumentsJob::dispatch($event->requestId, $event->userId)
            ->onQueue((string) config('edo.queue', 'edo'));
    }
}
