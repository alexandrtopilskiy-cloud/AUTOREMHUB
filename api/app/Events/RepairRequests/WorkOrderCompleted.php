<?php

declare(strict_types=1);

namespace App\Events\RepairRequests;

use App\Models\RepairRequest;
use App\Models\WorkOrder;
use Illuminate\Foundation\Events\Dispatchable;

final class WorkOrderCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly RepairRequest $request,
        public readonly WorkOrder $workOrder
    ) {}
}
