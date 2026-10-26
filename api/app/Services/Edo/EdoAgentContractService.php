<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\Edo\Builders\AgentContractBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;

final class EdoAgentContractService
{
    use SendsEdoDocuments;

    public function __construct(
        private readonly AgentContractBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendContract(Organization $customer): EdoDocument
    {
        return DB::transaction(function () use ($customer) {
            $ukTenant = Tenant::findOrFail($customer->tenant_id);
            $provider = $this->dispatcher->forOrganization($ukTenant);

            $document = EdoDocument::query()->firstOrCreate(
                [
                    'counterparty_id' => $customer->id,
                    'document_type'   => EdoDocumentType::AgencyContract->value,
                ],
                [
                    'tenant_id'         => $customer->tenant_id,
                    'organization_id'   => $ukTenant->id,
                    'provider'          => $ukTenant->edo_provider,
                    'number'            => 'AG-' . $customer->id,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => Organization::class,
                    'source_id'         => $customer->id,
                ]
            );

            $structuredRequest = $this->builder->build($customer, $document, $ukTenant);
            // 👇 ИЗВЛЕКАЕМ ФАЙЛ ИЗ META И СОЗДАЕМ DTO
            $fileDto = new EdoFile(
                name: $structuredRequest->meta['file_name'],
                content: base64_decode($structuredRequest->meta['file_content']),
                mimeType: 'application/pdf'
            );

            // Создаем фейковую подпись (для реального ЭДО здесь будут данные УКЭП)
            $signatureDto = new EdoSignature(
                base64: 'mock-signature',
                certificate: null
            );

            // 👇 ВЫЗЫВАЕМ НАШ ОРКЕСТРАТОР
            $result = $this->dispatchToProvider($provider, $structuredRequest, $fileDto, $signatureDto);

            $document->update([
                'status'      => 'sent',
                'external_id' => $result->documentId,
                'sent_at'     => now(),
            ]);

            return $document->refresh();
        });
    }
}
