<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RepairRequest;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RepairRequest */
final class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WorkOrder|null $order */
        $order = $this->workOrder;

        if ($order === null) {
            return ['workOrder' => null];
        }

        return [
            'workOrder' => [
                'id'          => $order->uuid,
                'systemId'    => $order->id,
                'number'      => $order->number,
                'mileage'     => $order->mileage,
                'completedAt' => $order->completed_at?->toISOString(),
                'total'       => (float) $order->total,
                'conclusion'  => $order->conclusion,
                'items'       => WorkOrderItemResource::collection($order->items),
            ],
        ];
    }
}
