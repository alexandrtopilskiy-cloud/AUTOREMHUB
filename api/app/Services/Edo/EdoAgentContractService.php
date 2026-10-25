<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Models\Attachment;
use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\Edo\Builders\AgentContractBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EdoAgentContractService
{
    public function __construct(
        private readonly AgentContractBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendContract(Organization $customer): EdoDocument
    {
        return DB::transaction(function () use ($customer) {
            // Предполагаем, что у customer есть tenant_id
            $ukOrg = Tenant::findOrFail($customer->tenant_id);

            // Отправитель - УК, получатель - Заказчик
            $provider = $this->dispatcher->forOrganization($ukOrg);

            $document = EdoDocument::query()->firstOrCreate(
                [
                    'counterparty_id' => $customer->id,
                    'document_type'   => EdoDocumentType::AgencyContract->value,
                ],
                [
                    'tenant_id'         => $customer->tenant_id,
                    'organization_id'   => $ukOrg->id,
                    'provider'          => $ukOrg->edo_provider,
                    'number'            => 'AG-' . $customer->id, // Или генератор номеров
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => Organization::class,
                    'source_id'         => $customer->id,
                ]
            );

            $structuredRequest = $this->builder->build($customer, $document, $ukOrg);
            $result = $provider->sendDocument($structuredRequest);

            $attachment = Attachment::query()->create([
                'repair_request_id' => null, // если это не заявка, а организация, возможно, нужно другое поле
                'disk' => 'local',
                'object_key' => 'edo/temp_' . $document->uuid . '.pdf',
                'original_name' => $structuredRequest->meta['file_name'],
                'mime_type' => AttachmentMime::Pdf,
                'size' => strlen(base64_decode($structuredRequest->meta['file_content'])),
                'purpose' => AttachmentPurpose::EdoPayload, // Создайте такой Enum
                'organization_id' => $customer->id,
                'created_by' => auth()->id() ?? 1,
            ]);
            Storage::disk('local')->put(
                $attachment->object_key,
                base64_decode($structuredRequest->meta['file_content'])
            );

            $document->update([
                'status'        => 'sent',
                'external_id'   => $result->documentId ?? null,
                'sent_at'       => now(),
                'meta'          => array_merge($document->meta ?? [], [
                    'provider_response' => $result->rawResponse ?? [],
                ]),
            ]);

            return $document->refresh();
        });
    }
}
