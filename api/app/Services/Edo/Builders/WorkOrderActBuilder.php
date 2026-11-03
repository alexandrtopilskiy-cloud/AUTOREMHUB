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
use App\Services\Edo\Xml\Upd503Builder;
use DateTimeImmutable;

final class WorkOrderActBuilder
{
    public function __construct(
        private readonly Upd503Builder $xmlBuilder,
    ) {}

    public function build(
        RepairRequest $request,
        WorkOrder $workOrder,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver,
    ): EdoStructuredRequest {

        // 1. Собираем строки таблицы (EdoInvoiceRow)
        $rows = [];
        foreach ($workOrder->items as $item) {
            $totalSum = bcmul((string)$item->quantity, (string)$item->price, 2);
            // Вычисляем НДС (20% включен в стоимость)
            $vatSum = bcdiv(bcmul($totalSum, '20', 2), '120', 2);
            $netSum = bcsub($totalSum, $vatSum, 2);

            $rows[] = new EdoInvoiceRow(
                name: $item->name,
                unit: '796', // 796 = шт. (ОКЕИ)
                amount: (string) $item->quantity,
                price: (string) $item->price,
                netSum: $netSum,
                vatSum: $vatSum,
                totalSum: $totalSum,
                vatType: '20%',
            );
        }

        // 2. Собираем данные сторон (EdoPartyInfo)
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

        // 3. Формируем payload
        $payload = new EdoInvoicePayload(
            seller: $seller,
            buyer: $buyer,
            rows: $rows,
        );

        // 4. Формируем подписанта (берем директора СТО)
        $signer = new EdoSignerDto(
            lastName: 'Иванов', // 👈 Замените на реальные данные из Organization (director_name)
            firstName: 'Иван',
            middleName: 'Иванович',
            title: 'Директор',
        );

        // 5. Генерируем XML
        $xmlContent = $this->xmlBuilder->build(
            payload: $payload,
            function: UpdFunction::SfDop,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            signer: $signer
        );

        $idFile = $this->xmlBuilder->getLastGeneratedIdFile();
        $fileName = $idFile . '.xml';

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
            totalSum: (string) $workOrder->total,
            vatSum: bcdiv(bcmul((string)$workOrder->total, '20', 2), '120', 2),
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
            ],
        );
    }
}
