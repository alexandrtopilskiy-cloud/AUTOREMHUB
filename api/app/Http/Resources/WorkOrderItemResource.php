<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkOrderItem */
final class WorkOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind'        => $this->kind->value,
            'name'        => $this->name,
            'qty'         => (float) $this->quantity,
            'price'       => (float) $this->price,
            'actualHours' => $this->actual_hours !== null ? (float) $this->actual_hours : null,
        ];
    }
}
