<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\VehicleAcceptanceAct;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;

final class AcceptanceActBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator
    ) {}

    public function build(
        RepairRequest $request,
        VehicleAcceptanceAct $act,
        EdoDocument $document,
        Organization $sender, // СТО
        Organization $receiver // УК
    ): EdoStructuredRequest {
        $pdfContent = $this->generator->generateAcceptanceAct($request, $act);

        return new EdoStructuredRequest(
            type: EdoDocumentType::AcceptanceAct,
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
            items: [], // У акта нет товарных позиций
            totalSum: '0.00',
            vatSum: '0.00',
            vatIncluded: false,
            meta: [
                'file_name'     => "Документ_{$document->number}.pdf",
                'file_content'  => base64_encode($pdfContent),
                'act_number'    => $act->number,
                'mileage'       => $act->mileage,
                'fuel_level'    => $act->fuel_level,
                'condition'     => $act->condition,
                'defects'       => $act->defect_types,
                'vehicle'       => $act->vehicle_snapshot,
            ],
        );
    }
}
