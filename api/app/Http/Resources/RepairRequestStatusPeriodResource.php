<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RequestStatusPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestStatusPeriod */
final class RepairRequestStatusPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $end = $this->exited_at ?? now();

        return [
            'id'        => $this->uuid,
            'stage'     => $this->stage->value,
            'enteredAt' => $this->entered_at->toISOString(),
            'exitedAt'  => $this->exited_at?->toISOString(),
            'seconds'   => max(0, (int) $this->entered_at->diffInSeconds($end)),
        ];
    }
}
