<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EdoDocument>
 */
final class EdoDocumentFactory extends Factory
{
    protected $model = EdoDocument::class;

    public function definition(): array
    {
        return [
            'uuid'                  => (string) Str::uuid(),
            'tenant_id'             => Tenant::factory(),
            'organization_id'       => Organization::factory(),
            'counterparty_id'       => null,
            'provider'              => EdoProvider::SberKorus->value,
            'document_type'         => EdoDocumentType::UpdSf->value,
            'status'                => EdoDocumentStatus::Draft->value,
            'number'                => 'УПД-' . Str::upper(Str::random(8)),
            'document_date'         => now()->format('Y-m-d'),
            'external_id'           => null,
            'external_uuid'         => null,
            'source_type'           => null,
            'source_id'             => null,
            'meta'                  => [],
            'sent_at'               => null,
            'delivered_at'          => null,
            'signed_at'             => null,
            'accepted_at'           => null,
            'rejected_at'           => null,
            'last_synced_at'        => null,
            'payload_attachment_id' => null,
            'signed_attachment_id'  => null,
        ];
    }
}
