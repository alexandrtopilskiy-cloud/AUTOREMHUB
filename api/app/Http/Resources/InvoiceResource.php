<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
final class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->uuid,
            'systemId'                  => $this->id,
            'number'                    => $this->number,
            'amount'                    => (float) $this->amount,
            'status'                    => $this->status->value,
            'documentAttachmentId'      => $this->documentAttachment?->uuid,
            'paidDocumentAttachmentId'  => $this->paidDocumentAttachment?->uuid,
            'paidAmount'                => $this->paid_amount !== null ? (float) $this->paid_amount : null,
            'paidAt'                    => $this->paid_at?->toISOString(),
            'verifiedAt'                => $this->verified_at?->toISOString(),
        ];
    }
}
