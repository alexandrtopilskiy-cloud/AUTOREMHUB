<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use DateTimeImmutable;

final class PowerOfAttorneyBuilder
{
    public function build(
        RepairRequest $request,
        EdoDocument $document,
        Organization $customer,
        Organization $station
    ): EdoStructuredRequest {

        // TODO: Здесь должен быть вызов сервиса генерации PDF (например, через DomPDF)
        // Пока передаем заглушку.
        $pdfContent = base64_encode('dummy pdf content');

        return new EdoStructuredRequest(
            type: EdoDocumentType::PowerOfAttorney,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                inn: $customer->inn,
                kpp: $customer->kpp ?? '',
                name: $customer->name,
            ),
            receiver: new EdoOrganizationDto(
                inn: $station->inn,
                kpp: $station->kpp ?? '',
                name: $station->name,
            ),
            items: [],          // У доверенности нет товарных позиций
            totalSum: '0.00',
            vatSum: '0.00',
            vatIncluded: false,
            meta: [
                'file_name' => "Доверенность_{$document->number}.pdf",
                'file_content' => $pdfContent,
                'vehicle_vin' => $request->vehicleRecord?->vin,
                'vehicle_plate' => $request->vehicleRecord?->plate,
            ],
        );
    }
}