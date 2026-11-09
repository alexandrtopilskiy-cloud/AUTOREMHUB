<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoInvoicePayload;
use App\DTO\EdoInvoiceRow;
use App\DTO\EdoOrganizationDto;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Services\DocumentGeneratorService;
use App\Services\Edo\Xml\InvoiceXmlBuilder;
use DateTimeImmutable;

final class InvoiceBuilder
{
    public function __construct(
        private readonly InvoiceXmlBuilder $xmlBuilder,
        private readonly DocumentGeneratorService $generator,
    ) {}

    public function build(
        RepairRequest $request,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver,
        bool $isStationToManager = false,
    ): EdoStructuredRequest {
        // 1. Сумма
        $amount = $isStationToManager
            ? $request->totalForStation()
            : $request->totalForCustomer();
        $vatAmount = bcdiv(bcmul($amount, '20', 2), '120', 2);

        // 2. Строки таблицы
        $rows = [
            new EdoInvoiceRow(
                name: $isStationToManager
                    ? 'Услуги СТО по заявке ' . $request->number
                    : 'Агентское вознаграждение по заявке ' . $request->number,
                unit: '796',
                amount: '1',
                price: $amount,
                netSum: bcsub($amount, $vatAmount, 2),
                vatSum: $vatAmount,
                totalSum: $amount,
                vatType: '20%',
            )
        ];

        // 3. Стороны
        $seller = new EdoPartyInfo(
            name: $sender->name,
            inn: $sender->inn,
            kpp: $sender->kpp ?? '',
            ediCode: $sender->edo_edi_code,
        );
        $buyer = new EdoPartyInfo(
            name: $receiver->name,
            inn: $receiver->inn,
            kpp: $receiver->kpp ?? '',
            ediCode: $receiver->edo_edi_code,
        );

        $payload = new EdoInvoicePayload(seller: $seller, buyer: $buyer, rows: $rows);

        // 4. Подписант
        $signer = new EdoSignerDto(
            lastName: 'Иванов',
            firstName: 'Иван',
            middleName: 'Иванович',
            title: 'Директор',
        );

        // 5. Генерируем XML
        $xmlContent = $this->xmlBuilder->build(
            payload: $payload,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            signer: $signer
        );

        $idFile = $this->xmlBuilder->getLastGeneratedIdFile();
        $fileName = $idFile . '.xml';

        // 6. PDF для мок-режима (визуализация)
        $pdfContent = null;
        if (config('edo.mock')) {
            $pdfContent = $this->generator->generateInvoice([
                'title' => 'Счёт',
                'doc' => $document,
                'payload' => ['request' => ['number' => $request->number]],
                'number' => $document->number,
                'date' => $document->document_date->format('d.m.Y'),
                'sender' => $sender,
                'receiver' => $receiver,
                'amount' => $amount,
                'vatAmount' => $vatAmount,
                'requestNumber' => $request->number,
                'isStationToManager' => $isStationToManager,
            ]);
        }

        return new EdoStructuredRequest(
            type: EdoDocumentType::Invoice,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                id: $sender->uuid,
                name: $sender->name,
                inn: $sender->inn,
                kpp: $sender->kpp ?? '',
            ),
            receiver: new EdoOrganizationDto(
                id: $receiver->uuid,
                name: $receiver->name,
                inn: $receiver->inn,
                kpp: $receiver->kpp ?? '',
            ),
            items: $rows,
            totalSum: $amount,
            vatSum: $vatAmount,
            vatIncluded: true,
            meta: [
                'file_name'         => $fileName, // 👈 .xml
                'file_content'      => base64_encode($xmlContent), // 👈 XML
                'pdf_content'       => $pdfContent ? base64_encode($pdfContent) : null,
                'tenant_id'         => $request->tenant_id,
                'sender_id'         => $sender->id,
                'receiver_id'       => $receiver->id,
                'source_type'       => RepairRequest::class,
                'source_id'         => $request->id,
                'repair_request_id' => $request->id,
                'work_order_number' => $request->workOrder?->number,
                'vehicle_info'      => $request->vehicleRecord?->toArray(),
            ],
        );
    }
}
