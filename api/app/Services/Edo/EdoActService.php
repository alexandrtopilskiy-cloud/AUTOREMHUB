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
            $sender = $request->stationRecord?->organization; // СТО
            $receiver = $request->ukOrganization; // УК

            if (!$sender) {
                throw new \RuntimeException("У заявки {$request->number} не назначена СТО (отправитель)");
            }
            if (!$receiver) {
                throw new \RuntimeException("У заявки {$request->number} не найдена УК (получатель)");
            }

            // Провайдер для кабинета СТО
            $provider = $this->dispatcher->forOrganization($sender);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::ActWorks->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $sender->id,
                    'counterparty_id'   => $receiver->id,
                    'provider'          => $sender->edo_provider ?? 'diadoc',
                    'number'            => 'ACT-' . $workOrder->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $workOrder, $document, $sender, $receiver);

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
