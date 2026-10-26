<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;

final class AgentReportBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator
    ) {}

    public function build(
        RepairRequest $request,
        EdoDocument $document,
        Organization $sender, // УК
        Organization $receiver // Заказчик
    ): EdoStructuredRequest {

        // Поскольку это отчет агента, передаем в items итоговые суммы по заказ-наряду
        $items = [
            [
                'name'      => 'Выполненные работы и запчасти по заказ-наряду',
                'quantity'  => '1',
                'price'     => (string) $request->workOrder?->total,
                'sum'       => (string) $request->workOrder?->total,
                'vatRate'   => '20',
                'vatSum'    => bcdiv(bcmul((string)$request->workOrder?->total, '20', 2), '120', 2),
            ]
        ];

        $pdfContent = $this->generator->generateAgentReport($request);

        return new EdoStructuredRequest(
            type: EdoDocumentType::NonFormalized, // В будущем можно заменить на AgentReport, если появится
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
            totalSum: (string) $request->workOrder?->total,
            vatSum: bcdiv(bcmul((string)$request->workOrder?->total, '20', 2), '120', 2),
            vatIncluded: true,
            meta: [
                'file_name'    => "Документ_{$document->number}.pdf",
                'file_content' => base64_encode($pdfContent),
                'work_order_number' => $request->workOrder?->number,
                'vehicle_info' => $request->vehicleRecord?->toArray(),
                'report_type' => 'agent_report'
            ],
        );
    }
}
