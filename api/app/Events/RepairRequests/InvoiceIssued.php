<?php

declare(strict_types=1);

namespace App\Events\RepairRequests;

use App\Models\Invoice;
use App\Models\RepairRequest;
use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceIssued
{
    use Dispatchable;

    public function __construct(
        public readonly RepairRequest $request,
        public readonly Invoice $invoice
    ) {}
}
