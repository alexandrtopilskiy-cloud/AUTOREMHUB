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
use App\Services\DocumentGeneratorService;

final class PowerOfAttorneyBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator,
    ) {}

    public function build(
        RepairRequest $request,
        EdoDocument $document,
        Organization $customer,
        Organization $station
    ): EdoStructuredRequest {

        $pdfContent = $this->generator->generatePowerOfAttorney($request);

        return new EdoStructuredRequest(
            type: EdoDocumentType::PowerOfAttorney,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                id: $customer->uuid,
                name: $customer->name,
                inn: $customer->inn,
                kpp: $customer->kpp ?? '',
            ),
            receiver: new EdoOrganizationDto(
                id: $station->uuid,
                name: $station->name,
                inn: $station->inn,
                kpp: $station->kpp ?? '',
            ),
            items: [],
            totalSum: '0.00',
            vatSum: '0.00',
            vatIncluded: false,
            meta: [
                'file_name'     => "Доверенность_{$document->number}.pdf",
                'file_content'  => base64_encode($pdfContent),
                'vehicle_vin'   => $request->vehicleRecord?->vin,
                'vehicle_plate' => $request->vehicleRecord?->plate,
            ],
        );
    }
}