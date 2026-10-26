<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;

final class InvoiceBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator,
    ) {}

    public function build(
        RepairRequest $request,
        Invoice $invoice,
        EdoDocument $document,
        Organization $sender, // УК
        Organization $receiver // Заказчик
    ): EdoStructuredRequest {

        $amount = (string) $invoice->amount;
        $vatAmount = bcdiv(bcmul($amount, '20', 2), '120', 2);

        $items = [
            [
                'name'      => 'Агентское вознаграждение по заявке ' . $request->number,
                'quantity'  => '1',
                'price'     => $amount,
                'sum'       => $amount,
                'vatRate'   => '20',
                'vatSum'    => $vatAmount,
            ]
        ];

        $pdfContent = $this->generator->generateInvoice($request, $invoice);

        return new EdoStructuredRequest(
            type: EdoDocumentType::Invoice,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                inn: $sender->inn,
                kpp: $sender->kpp ?? '',
                name: $sender->name,
            ),
            receiver: new EdoOrganizationDto(
                inn: $receiver->inn,
                kpp: $receiver->kpp ?? '',
                name: $receiver->name,
            ),
            items: $items,
            totalSum: $amount,
            vatSum: $vatAmount,
            vatIncluded: true,
            meta: [
                'file_name'         => "Документ_{$document->number}.pdf",
                'file_content'      => base64_encode($pdfContent),
                'repair_request_id' => $request->id,
                'work_order_number' => $request->workOrder?->number,
                'vehicle_info'      => $request->vehicleRecord?->toArray(),
            ],
        );
    }
}
