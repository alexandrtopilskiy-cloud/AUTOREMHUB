<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RequestApproval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestApproval */
final class RepairRequestApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->uuid,
            'stage'           => $this->stage->value,
            'decision'        => $this->decision->value,
            'comment'         => $this->comment,
            'estimateVersion' => $this->estimate_version,
            'createdAt'       => $this->created_at->toISOString(),
        ];
    }
}
