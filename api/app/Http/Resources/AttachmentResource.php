<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attachment */
final class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->uuid,
            'name'     => $this->original_name,
            'mime'     => $this->mime_type->value,
            'size'     => $this->size,
            'purpose'  => $this->purpose->value,
            'periodId' => $this->request_status_period_id ? $this->period?->uuid : null,
            'linked'   => $this->request_event_id !== null,
        ];
    }
}
