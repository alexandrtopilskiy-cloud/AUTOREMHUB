<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Invoice;
use App\Models\RepairRequest;
use App\Services\Edo\Builders\InvoiceBuilder;
use Illuminate\Support\Facades\DB;

final class EdoInvoiceService
{
    public function __construct(
        private readonly InvoiceBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendInvoice(RepairRequest $request, Invoice $invoice): EdoDocument
    {
        return DB::transaction(function () use ($request, $invoice) {
            $ukOrg = $request->tenant; // УК
            $customerOrg = $request->customerOrganization; // Заказчик

            if (!$customerOrg) {
                throw new \RuntimeException("У заявки {$request->number} нет Заказчика");
            }

            // Отправитель - УК, получатель - Заказчик
            $provider = $this->dispatcher->forOrganization($ukOrg);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::Invoice->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $ukOrg->id,
                    'counterparty_id'   => $customerOrg->id,
                    'provider'          => $ukOrg->edo_provider,
                    'number'            => 'INV-' . $invoice->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $invoice, $document, $ukOrg, $customerOrg);
            $result = $provider->sendDocument($structuredRequest);

            $document->update([
                'status'        => 'sent',
                'external_id'   => $result->documentId ?? null,
                'sent_at'       => now(),
                'meta'          => array_merge($document->meta ?? [], [
                    'provider_response' => $result->rawResponse ?? [],
                ]),
            ]);

            return $document->refresh();
        });
    }
}
