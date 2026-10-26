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
            $sender = $request->customerOrganization; // Заказчик
            $receiver = $request->stationRecord?->organization; // СТО

            if (!$sender) {
                throw new \RuntimeException("У заявки {$request->number} не найден Заказчик (отправитель)");
            }
            if (!$receiver) {
                throw new \RuntimeException("У заявки {$request->number} не назначена СТО (получатель)");
            }

            $provider = $this->dispatcher->forOrganization($sender);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::PowerOfAttorney->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $sender->id, // Кто отправляет
                    'counterparty_id'   => $receiver->id, // Кому отправляем
                    'provider'          => $sender->edo_provider ?? 'diadoc',
                    'number'            => 'POA-' . $request->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => EdoDocumentStatus::Draft,
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $document, $sender, $receiver);

            $fileContent = base64_decode($structuredRequest->meta['file_content']);
            $fileName = $structuredRequest->meta['file_name'];

            $fileDto = new EdoFile(
                filename: $fileName,
                content: $fileContent,
                mimeType: AttachmentMime::Pdf->value,
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
