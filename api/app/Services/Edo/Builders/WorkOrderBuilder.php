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

final class WorkOrderBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator
    ) {}

    public function build(
        RepairRequest $req,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver
    ): EdoStructuredRequest {

        $workOrder = $req->workOrder;
        if (!$workOrder) {
            throw new \RuntimeException("У заявки {$req->number} отсутствует заказ-наряд.");
        }

        // 1. Генерируем PDF
        $pdfContent = $this->generator->generateWorkOrder($req, $workOrder, $sender, $receiver);

        return new EdoStructuredRequest(
            type: EdoDocumentType::NonFormalized, // <-- Указываем, что это неформализованный документ
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
            items: [],
            totalSum: number_format((float) $workOrder->total, 2, '.', ''),
            vatSum: '0.00',
            vatIncluded: true,
            meta: [
                // Для неформализованного документа xml_content не нужен
                'file_name'         => "Заказ-наряд_{$document->number}.pdf",
                'file_content'      => base64_encode($pdfContent),
                'repair_request_id' => $req->id,
                'uploaded_by'       => $req->created_by,
            ],
        );
    }
}
