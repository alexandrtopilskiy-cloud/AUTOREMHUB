<?php

declare(strict_types=1);

namespace App\Events\RepairRequests;

use App\Models\RepairRequest;
use App\Models\VehicleAcceptanceAct;
use Illuminate\Foundation\Events\Dispatchable;

final class VehicleAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly RepairRequest $request,
        public readonly VehicleAcceptanceAct $act
    ) {}
}
