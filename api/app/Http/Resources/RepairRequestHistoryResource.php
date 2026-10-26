<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RepairRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RepairRequest */
final class RepairRequestHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['events', 'periods', 'approvals']);

        return [
            'history'       => RepairRequestEventResource::collection($this->events),
            'statusPeriods' => RepairRequestStatusPeriodResource::collection($this->periods),
            'approvals'     => RepairRequestApprovalResource::collection($this->approvals),
        ];
    }
}
