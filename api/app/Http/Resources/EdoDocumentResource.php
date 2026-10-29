<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EdoDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EdoDocument */
final class EdoDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->uuid,
            'type'            => $this->document_type,
            'typeLabel'       => $this->document_type->label(),
            'number'          => $this->number,
            'date'            => $this->document_date->format('d.m.Y'),
            'provider'        => $this->provider,
            'status'          => $this->status,
            'statusLabel'     => $this->status->label(),
            'direction'       => $this->organization_id === $request->user()->organization_id ? 'outgoing' : 'incoming',
            'counterparty'    => $this->counterparty?->name ?? '—',
            'source'          => [
                'type' => $this->source_type,
                'id'   => $this->source_id,
            ],
            'sentAt'          => $this->sent_at?->toISOString(),
            'deliveredAt'     => $this->delivered_at?->toISOString(),
            'signedAt'        => $this->signed_at?->toISOString(),
            'lastSyncedAt'    => $this->last_synced_at?->toISOString(),
            'canRetry'        => in_array($this->status, ['rejected', 'unknown'], true),
            'canDownload'     => $this->payload_attachment_id !== null,
            'pdfUrl'          => $this->payload_attachment_id ? "/api/attachments/{$this->payloadAttachment->uuid}/download" : null,
        ];
    }
}