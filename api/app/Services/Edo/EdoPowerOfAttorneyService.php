<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Models\Attachment;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\Edo\Builders\PowerOfAttorneyBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

            // 1. Создаем запись в Attachment, чтобы файл появился в карточке заявки
            $attachment = Attachment::create([
                'uuid'                      => Str::uuid(),
                'repair_request_id'         => $request->id,
                'uploaded_by'               => auth()->id() ?? $request->created_by, // Или системный пользователь
                'request_status_period_id'  => $request->periods()->whereNull('exited_at')->first()?->id,
                'disk'                      => 'local',
                'object_key'                => $objectKey,
                'original_name'             => $structuredRequest->meta['file_name'],
                'mime_type'                 => AttachmentMime::Pdf,
                'size'                      => strlen($fileContent),
                'sha256'                    => hash('sha256', $fileContent),
                'purpose'                   => AttachmentPurpose::EdoPayload, // Используем ваш Enum
            ]);

            // 2. Привязываем файл к документу ЭДО
            $document->update([
                'status'                => EdoDocumentStatus::Sended,
                'external_id'           => $result->externalId,
                'sent_at'               => now(),
                'payload_attachment_id' => $attachment->id, // 👈 Ссылка на файл
                'meta'                  => array_merge($document->meta ?? [], ['local_file' => $objectKey]),
            ]);

            return $document->refresh();
        });
    }
}
