<?php

declare(strict_types=1);

namespace App\Events\RepairRequests;

use App\Models\RepairRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ClosingDocumentsRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly RepairRequest $request) {}
}
