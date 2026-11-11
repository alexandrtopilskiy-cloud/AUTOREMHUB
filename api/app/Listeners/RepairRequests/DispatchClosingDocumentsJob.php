<?php

declare(strict_types=1);

namespace App\Listeners\RepairRequests;

use App\Events\RepairRequests\ClosingDocumentsRequested;
use App\Jobs\Edo\GenerateClosingDocumentsJob;

class DispatchClosingDocumentsJob
{
    public function handle(ClosingDocumentsRequested $event): void
    {
        // Отправляем в очередь, чтобы не тормозить основной процесс
        GenerateClosingDocumentsJob::dispatch($event->request)->onQueue('edo');
    }
}
