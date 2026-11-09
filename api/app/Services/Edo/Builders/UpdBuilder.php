<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\DTO\EdoInvoicePayload;
use App\DTO\EdoInvoiceRow;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\UpdFunction;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\WorkOrder;
use App\Services\DocumentGeneratorService;
use App\Services\Edo\Xml\Upd503Builder;
use DateTimeImmutable;

final class UpdBuilder
{
    public function __construct(
        private readonly Upd503Builder $xmlBuilder,
        private readonly DocumentGeneratorService $generator, // 👈 Добавили
    ) {}

    public function build(
        RepairRequest $request,
        WorkOrder $workOrder,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver,
        bool $isStationToManager = false, // 👈 Добавили флаг
    ): EdoStructuredRequest {
        // 1. Сумма для контекста
        $totalSum = $isStationToManager
            ? $request->totalForStation()
            : $request->totalForCustomer();

        // 2. Собираем строки таблицы (EdoInvoiceRow)
        $rows = [];
        foreach ($workOrder->items as $item) {
            $lineTotal = bcmul((string)$item->quantity, (string)$item->price, 2);

            // Если СТО -> УК, применяем скидку (берем из RequestWork/RequestPart, для простоты берем 10%)
            if ($isStationToManager) {
                $discount = '10'; // TODO: Брать реальную скидку
                $lineTotal = bcsub($lineTotal, bcdiv(bcmul($lineTotal, $discount, 4), '100', 2), 2);
            }

            $vatSum = bcdiv(bcmul($lineTotal, '20', 2), '120', 2);
            $netSum = bcsub($lineTotal, $vatSum, 2);

            $rows[] = new EdoInvoiceRow(
                name: $item->name,
                unit: '796',
                amount: (string) $item->quantity,
                price: (string) $item->price,
                netSum: $netSum,
                vatSum: $vatSum,
                totalSum: $lineTotal,
                vatType: '20%',
            );
        }

        // 3. Собираем данные сторон (EdoPartyInfo)
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

        // 4. Формируем payload
        $payload = new EdoInvoicePayload(
            seller: $seller,
            buyer: $buyer,
            rows: $rows,
        );

        // 5. Формируем подписанта
        $signer = new EdoSignerDto(
            lastName: 'Иванов',
            firstName: 'Иван',
            middleName: 'Иванович',
            title: 'Директор',
        );

        // 6. Генерируем XML
        $xmlContent = $this->xmlBuilder->build(
            payload: $payload,
            function: UpdFunction::SfDop,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            signer: $signer
        );

        $idFile = $this->xmlBuilder->getLastGeneratedIdFile();
        $fileName = $idFile . '.xml';

        // 7. Готовим данные для PDF (только для мок-режима)
        $pdfContent = null;
        if (config('edo.mock')) {
            $pdfContent = $this->generator->generateUpd([
                'title' => 'УПД',
                'doc' => $document,
                'payload' => [
                    'request' => ['number' => $request->number],
                ],
                'number' => $document->number,
                'date' => $document->document_date->format('d.m.Y'),
                'seller' => $sender,
                'buyer' => $receiver,
                'rows' => $rows,
                'totalSum' => $totalSum,
                'vatSum' => bcdiv(bcmul($totalSum, '20', 2), '120', 2),
                'basis' => 'Заказ-наряд № ' . $workOrder->number,
            ]);
        }

        return new EdoStructuredRequest(
            type: EdoDocumentType::UpdSfDop,
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
            totalSum: $totalSum,
            vatSum: bcdiv(bcmul($totalSum, '20', 2), '120', 2),
            vatIncluded: true,
            meta: [
                'file_name'         => $fileName,
                'file_content'      => base64_encode($xmlContent),
                'tenant_id'         => $request->tenant_id,
                'sender_id'         => $sender->id,
                'receiver_id'       => $receiver->id,
                'source_type'       => RepairRequest::class,
                'source_id'         => $request->id,
                'repair_request_id' => $request->id,
                'work_order_id'     => $workOrder->id,
                'work_order_number' => $workOrder->number,
                'mileage'           => $workOrder->mileage,
                'vehicle_info'      => $request->vehicleRecord?->toArray(),
                'pdf_content'       => $pdfContent ? base64_encode($pdfContent) : null,
            ],
        );
    }
}
