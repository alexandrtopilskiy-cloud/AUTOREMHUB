<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\Enums\AttachmentMime;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Models\VehicleAcceptanceAct;
use App\Services\Edo\Builders\AcceptanceActBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EdoAcceptanceActService
{
    use SendsEdoDocuments;

    public function __construct(
        private readonly AcceptanceActBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function send(RepairRequest $request, VehicleAcceptanceAct $act): EdoDocument
    {
        return DB::transaction(function () use ($request, $act) {
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
                ['document_type' => EdoDocumentType::AcceptanceAct->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $stationOrg->id,
                    'counterparty_id'   => $ukOrg->id,
                    'provider'          => $stationOrg->edo_provider ?? 'diadoc',
                    'number'            => $act->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $act, $document, $stationOrg, $ukOrg);

            $fileContent = base64_decode($structuredRequest->meta['file_content']);
            $fileDto = new EdoFile(
                filename: $structuredRequest->meta['file_name'],
                content: $fileContent,
                mimeType: AttachmentMime::Pdf->value
            );
            $objectKey = 'edo/' . $document->uuid . '.pdf';
            Storage::disk('local')->put($objectKey, $fileContent);

            $signatureDto = new EdoSignature(
                content: 'mock-signature',
                certificateFingerprint : 'mock-cert',
            );
            $result = $this->dispatchToProvider($provider, $structuredRequest, $fileDto, $signatureDto);

            $document->update([
                'status'      => EdoDocumentStatus::Sended,
                'external_id' => $result->externalId,
                'sent_at'     => now(),
                'meta'        => array_merge($document->meta ?? [], ['local_file' => $objectKey]),
            ]);

            return $document->refresh();
        });
    }
}
