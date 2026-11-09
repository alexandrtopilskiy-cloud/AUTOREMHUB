<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Services\Edo\Xml\AgentReportXmlBuilder;
use DateTimeImmutable;

final class AgentReportBuilder
{
    public function __construct(
        private readonly AgentReportXmlBuilder $xmlBuilder,
    ) {}

    public function build(
        RepairRequest $request,
        EdoDocument $document,
        Organization $sender, // УК
        Organization $receiver // Заказчик
    ): EdoStructuredRequest {
        $amount = $request->agentFee();
        $vatAmount = bcdiv(bcmul($amount, '20', 2), '120', 2);

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

        $signer = new EdoSignerDto(
            lastName: 'Иванов',
            firstName: 'Иван',
            middleName: 'Иванович',
            title: 'Директор',
        );

        $xmlContent = $this->xmlBuilder->build(
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            agent: $seller,
            principal: $buyer,
            amount: $amount,
            vatAmount: $vatAmount,
            signer: $signer
        );

        $idFile = $this->xmlBuilder->getLastGeneratedIdFile();
        $fileName = $idFile . '.xml';

        return new EdoStructuredRequest(
            type: EdoDocumentType::NonFormalized,
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
            totalSum: $amount,
            vatSum: $vatAmount,
            vatIncluded: true,
            meta: [
                'file_name'         => $fileName,
                'file_content'      => base64_encode($xmlContent),
                'tenant_id'         => $request->tenant_id,
                'sender_id'         => $sender->id,
                'receiver_id'       => $receiver->id,
                'source_type'       => RepairRequest::class,
                'source_id'         => $request->id,
                'work_order_number' => $request->workOrder?->number,
                'vehicle_info'      => $request->vehicleRecord?->toArray(),
                'report_type'       => 'agent_report'
            ],
        );
    }
}
