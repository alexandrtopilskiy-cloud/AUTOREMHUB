<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use DateTimeImmutable;

final class WorkOrderBuilder
{
    public function build(RepairRequest $req, EdoDocument $document): EdoStructuredRequest
    {
        // Пока это заглушка. Здесь в будущем будет генерация XML/PDF для Заказ-наряда.

        return new EdoStructuredRequest(
            type: EdoDocumentType::WorkOrder, // Убедитесь, что этот тип есть в вашем Enum
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                id: '00000000-0000-0000-0000-000000000000',
                name: 'Заглушка Отправителя',
                inn: '0000000000',
                kpp: '000000000',
            ),
            receiver: new EdoOrganizationDto(
                id: '00000000-0000-0000-0000-000000000001',
                name: 'Заглушка Получателя',
                inn: '1111111111',
                kpp: '111111111',
            ),
            items: [],
            totalSum: '0.00',
            vatSum: '0.00',
            vatIncluded: false,
            meta: [
                // Пока просто пустая заглушка для XML
                'xml_content'       => '<?xml version="1.0" encoding="UTF-8"?><Файл></Файл>',
                'file_name'         => "Заказ-наряд_{$document->number}.pdf",
                'file_content'      => base64_encode('mock pdf content'), // Фейковый PDF
                'repair_request_id' => $req->id,
            ],
        );
    }
}
