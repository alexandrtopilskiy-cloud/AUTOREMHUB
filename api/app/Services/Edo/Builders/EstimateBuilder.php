<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Services\RepairRequestCalculator;
use DateTimeImmutable;

final class EstimateBuilder
{
    public function __construct(
        private readonly RepairRequestCalculator $calculator
    ) {}

    public function build(
        RepairRequest $request,
        EdoDocument $document,
        Organization $sender, // УК
        Organization $receiver // Заказчик
    ): EdoStructuredRequest {

        $items = [];

        // Добавляем работы
        foreach ($request->workLines()->get() as $work) {
            $items[] = [
                'name' => $work->name,
                'quantity' => (string) $work->quantity,
                'price' => (string) $work->price,
                'sum' => (string) $work->line_amount,
                'vatRate' => '20', // или берем из настроек
                'vatSum' => bcdiv(bcmul((string)$work->line_amount, '20', 2), '120', 2),
            ];
        }

        // Добавляем запчасти
        foreach ($request->parts()->get() as $part) {
            $remainingQty = bcsub((string) $part->quantity, (string) $part->returned_quantity, 3);
            if (bccomp($remainingQty, '0', 3) <= 0) continue;

            $sum = bcmul($remainingQty, (string) $part->price, 2);
            $items[] = [
                'name' => $part->name,
                'quantity' => $remainingQty,
                'price' => (string) $part->price,
                'sum' => $sum,
                'vatRate' => '20',
                'vatSum' => bcdiv(bcmul($sum, '20', 2), '120', 2),
            ];
        }

        $totalSum = $this->calculator->total($request);
        $vatSum = bcdiv(bcmul($totalSum, '20', 2), '120', 2); // Упрощенно

        return new EdoStructuredRequest(
            type: EdoDocumentType::WorkOrder,
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
            totalSum: $totalSum,
            vatSum: $vatSum,
            vatIncluded: true,
            meta: [
                'file_name'    => "Документ_{$document->number}.pdf",
                'file_content' => base64_encode("Текст заглушки для документа {$document->number}"), // Пока просто текст
                'diagnostic_report_id' => $request->diagnoses()->latest('id')->first()?->id,
                'vehicle_info' => $request->vehicleRecord?->toArray(),
            ],
        );
    }
}
