<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Edo\EdoDocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EdoDocument
 */
final class EdoDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->uuid,
            'uuid'           => $this->uuid,

            'documentType'   => $this->document_type->value,
            'documentLabel'  => $this->document_type->label(),

            'status'         => $this->status->value,
            'statusLabel'    => $this->status->label(),

            'provider'       => $this->provider->value,
            'providerLabel'  => $this->provider->label(),

            'number'         => $this->number,
            'documentDate'   => $this->document_date->toDateString(),

            'externalId'     => $this->external_id,
            'externalUuid'   => $this->external_uuid,

            'organization'   => $this->whenLoaded('organization', fn () => [
                'id'   => $this->organization->uuid,
                'name' => $this->organization->short_name,
                'inn'  => $this->organization->inn,
            ]),

            'counterparty'   => $this->whenLoaded('counterparty', fn () => $this->counterparty === null ? null : [
                'id'   => $this->counterparty->uuid,
                'name' => $this->counterparty->short_name,
                'inn'  => $this->counterparty->inn,
            ]),

            'sourceType'     => $this->source_type,
            'sourceId'       => $this->source_id,

            'payloadAttachmentId' => $this->payloadAttachment?->uuid,
            'signedAttachmentId'  => $this->signedAttachment?->uuid,
            'acceptanceAttachmentUuid' => $this->whenLoaded('acceptanceAttachment', fn () => $this->acceptanceAttachment?->uuid),

            'sentAt'         => $this->sent_at?->toISOString(),
            'deliveredAt'    => $this->delivered_at?->toISOString(),
            'signedAt'       => $this->signed_at?->toISOString(),
            'acceptedAt'     => $this->accepted_at?->toISOString(),
            'rejectedAt'     => $this->rejected_at?->toISOString(),
            'lastSyncedAt'   => $this->last_synced_at?->toISOString(),

            'createdAt'      => $this->created_at?->toISOString(),
            'updatedAt'      => $this->updated_at?->toISOString(),
        ];
    }
}
