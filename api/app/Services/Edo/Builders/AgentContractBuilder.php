<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\Tenant;
use DateTimeImmutable;

final class AgentContractBuilder
{
    public function build(
        Organization $customer,
        EdoDocument $document,
        Tenant $ukTenant // Или Organization, если УК тоже организация
    ): EdoStructuredRequest {

        // Генерация PDF договора (заглушка)
        $pdfContent = base64_encode('dummy agent contract pdf');

        return new EdoStructuredRequest(
            type: EdoDocumentType::AgencyContract,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                inn: $ukTenant->inn,
                kpp: $ukTenant->kpp ?? '',
                name: $ukTenant->name,
            ),
            receiver: new EdoOrganizationDto(
                inn: $customer->inn,
                kpp: $customer->kpp ?? '',
                name: $customer->name,
            ),
            items: [],
            totalSum: '0.00',
            vatSum: '0.00',
            vatIncluded: false,
            meta: [
                'file_name' => "Агентский_договор_{$customer->name}.pdf",
                'file_content' => $pdfContent,
                'organization_id' => $customer->id,
            ],
        );
    }
}
