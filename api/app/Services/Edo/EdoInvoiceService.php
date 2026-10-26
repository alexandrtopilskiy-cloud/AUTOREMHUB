<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\DTO\EdoFile;
use App\DTO\EdoSignature;
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
            $ukOrg = $request->customerOrganization;
            if (!$ukOrg) {
                throw new \RuntimeException("Не найдена организация для отправки документа по заявке {$request->number}");
            }
            $customerOrg = $request->customerOrganization;

            if (!$customerOrg) {
                throw new \RuntimeException("У заявки {$request->number} нет Заказчика");
            }

            $provider = $this->dispatcher->forOrganization($ukOrg);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::Invoice->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $ukOrg->id,
                    'counterparty_id'   => $customerOrg->id,
                    'provider'          => $ukOrg->edo_provider ?? 'diadoc',
                    'number'            => 'INV-' . $invoice->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $invoice, $document, $ukOrg, $customerOrg);

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
