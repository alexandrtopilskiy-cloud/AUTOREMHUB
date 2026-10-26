<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\Enums\AttachmentMime;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Invoice;
use App\Models\RepairRequest;
use App\Services\Edo\Builders\InvoiceBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EdoInvoiceService
{
    use SendsEdoDocuments;

    public function __construct(
        private readonly InvoiceBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendInvoice(RepairRequest $request, Invoice $invoice): EdoDocument
    {
        return DB::transaction(function () use ($request, $invoice) {
            $sender = $request->ukOrganization; // УК
            $receiver = $request->customerOrganization; // Заказчик

            if (!$sender) {
                throw new \RuntimeException("У заявки {$request->number} не найдена УК (отправитель)");
            }
            if (!$receiver) {
                throw new \RuntimeException("У заявки {$request->number} не найден Заказчик (получатель)");
            }

            // Провайдер для кабинета УК
            $provider = $this->dispatcher->forOrganization($sender);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::Invoice->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $sender->id,
                    'counterparty_id'   => $receiver->id,
                    'provider'          => $ukOrg->edo_provider ?? 'diadoc',
                    'number'            => 'INV-' . $invoice->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $invoice, $document, $sender, $receiver);

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
