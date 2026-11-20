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
use App\Models\User;
use App\Services\Edo\Builders\EstimateBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class EdoEstimateService
{
    use SendsEdoDocuments;

    public function __construct(
        private readonly EstimateBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendEstimate(RepairRequest $req): EdoDocument
    {
        return DB::transaction(function () use ($req) {
            $sender = $req->ukOrganization; // УК
            $receiver = $req->customerOrganization; // Заказчик

            if (!$sender) {
                throw new \RuntimeException("У заявки {$req->number} не найдена УК (отправитель)");
            }
            if (!$receiver) {
                throw new \RuntimeException("У заявки {$req->number} не найден Заказчик (получатель)");
            }

            // Провайдер для кабинета УК
            $provider = $this->dispatcher->forOrganization($sender);

            $document = $req->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::WorkOrder->value],
                [
                    'tenant_id'         => $req->tenant_id,
                    'organization_id'   => $sender->id,
                    'counterparty_id'   => $receiver->id,
                    'provider'          => $this->dispatcher->providerFor($sender)->value,
                    'number'            => 'EST-' . $req->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => EdoDocumentStatus::Draft,
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $req->id,
                ]
            );

            $structuredRequest = $this->builder->build($req, $document, $sender, $receiver);

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

            /** @var User $user */
            $user = auth();

            // 1. Создаем запись в Attachment, чтобы файл появился в карточке заявки
            $attachment = Attachment::create([
                'uuid'                      => Str::uuid(),
                'repair_request_id'         => $req->id,
                'uploaded_by'               => $user->id() ?? $req->created_by, // Или системный пользователь
                'request_status_period_id'  => $req->periods()->whereNull('exited_at')->first()?->id,
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
                'status'                => $this->dispatchedStatus($result),
                'external_id'           => $result->externalId,
                'sent_at'               => now(),
                'payload_attachment_id' => $attachment->id, // 👈 Ссылка на файл
                'meta'                  => array_merge($document->meta ?? [], ['local_file' => $objectKey], $this->dispatchedMeta($result)),
            ]);

            return $document->refresh();
        });
    }
}
