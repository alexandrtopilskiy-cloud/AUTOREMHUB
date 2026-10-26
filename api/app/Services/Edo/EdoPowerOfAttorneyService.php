<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\Edo\Builders\PowerOfAttorneyBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EdoPowerOfAttorneyService
{
    use SendsEdoDocuments;

    public function __construct(
        private readonly PowerOfAttorneyBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendPowerOfAttorney(RepairRequest $request): EdoDocument
    {
        return DB::transaction(function () use ($request) {
            $customer = $request->customerOrganization;
            $station = $request->stationRecord?->organization;

            if (!$station) {
                throw new \RuntimeException("У заявки {$request->number} не назначена СТО");
            }

            $providerOrg = $customer->edo_provider ? $customer : $request->tenant;
            $provider = $this->dispatcher->forOrganization($providerOrg);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::PowerOfAttorney->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $providerOrg->id,
                    'counterparty_id'   => $station->id,
                    'provider'          => $providerOrg->edo_provider ?? 'diadoc',
                    'number'            => 'POA-' . $request->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $document, $customer, $station);

            $fileContent = base64_decode($structuredRequest->meta['file_content']);
            $fileName = $structuredRequest->meta['file_name'];

            $fileDto = new EdoFile(name: $fileName, content: $fileContent, mimeType: 'application/pdf');
            $objectKey = 'edo/' . $document->uuid . '.pdf';
            Storage::disk('local')->put($objectKey, $fileContent);

            $signatureDto = new EdoSignature(base64: 'mock-signature', certificate: 'mock-cert');

            $result = $this->dispatchToProvider($provider, $structuredRequest, $fileDto, $signatureDto);

            $document->update([
                'status'      => 'sent',
                'external_id' => $result->documentId,
                'sent_at'     => now(),
                'meta'        => array_merge($document->meta ?? [], ['local_file' => $objectKey]),
            ]);

            return $document->refresh();
        });
    }
}
