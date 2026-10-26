<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Models\WorkOrder;
use App\Services\Edo\Builders\WorkOrderActBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EdoActService
{
    use SendsEdoDocuments;

    public function __construct(
        private readonly WorkOrderActBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendAct(RepairRequest $request, WorkOrder $workOrder): EdoDocument
    {
        return DB::transaction(function () use ($request, $workOrder) {
            $stationOrg = $request->stationRecord?->organization;
            $ukOrg = $request->customerOrganization;
            if (!$ukOrg) {
                throw new \RuntimeException("Не найдена организация для отправки документа по заявке {$request->number}");
            }

            if (!$stationOrg) {
                throw new \RuntimeException("У заявки {$request->number} не назначена СТО");
            }

            $provider = $this->dispatcher->forOrganization($stationOrg);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::ActWorks->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $stationOrg->id,
                    'counterparty_id'   => $ukOrg->id,
                    'provider'          => $stationOrg->edo_provider ?? 'diadoc',
                    'number'            => 'ACT-' . $workOrder->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $workOrder, $document, $stationOrg, $ukOrg);

            $fileContent = base64_decode($structuredRequest->meta['file_content']);
            $fileDto = new EdoFile(
                name: $structuredRequest->meta['file_name'],
                content: $fileContent,
                mimeType: 'application/pdf'
            );
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
