<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\WorkOrder;
use DateTimeImmutable;

final class WorkOrderActBuilder
{
    public function build(
        RepairRequest $request,
        WorkOrder $workOrder,
        EdoDocument $document,
        Organization $sender, // СТО
        Organization $receiver // УК
    ): EdoStructuredRequest {

        $items = [];

        // Берем позиции из сохраненного WorkOrder (они уже зафиксированы)
        foreach ($workOrder->items as $item) {
            $items[] = [
                'name' => $item->name,
                'quantity' => (string) $item->quantity,
                'price' => (string) $item->price,
                'sum' => bcmul((string)$item->quantity, (string)$item->price, 2),
                'vatRate' => '20', // Уточните, как у вас считается НДС
                'vatSum' => bcdiv(bcmul(bcmul((string)$item->quantity, (string)$item->price, 2), '20', 2), '120', 2),
            ];
        }

        return new EdoStructuredRequest(
            type: EdoDocumentType::ActWorks,
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
            totalSum: (string) $workOrder->total,
            vatSum: bcdiv(bcmul((string)$workOrder->total, '20', 2), '120', 2),
            vatIncluded: true,
            meta: [
                'work_order_id' => $workOrder->id,
                'work_order_number' => $workOrder->number,
                'mileage' => $workOrder->mileage,
                'vehicle_info' => $request->vehicleRecord?->toArray(),
            ],
        );
    }
}
