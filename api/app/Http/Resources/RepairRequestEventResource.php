<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RequestEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestEvent */
final class RepairRequestEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = (string) config('sto.service_timezone');

        return [
            'id'     => $this->uuid,
            'time'   => $this->occurred_at->setTimezone($timezone)->format('d.m.Y H:i'),
            'who'    => $this->actor_label,
            'text'   => $this->comment,
            'action' => $this->action,
            'stage'  => $this->stage->value,
        ];
    }
}
