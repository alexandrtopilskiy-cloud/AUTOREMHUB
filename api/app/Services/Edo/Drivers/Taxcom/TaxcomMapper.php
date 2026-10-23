<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Taxcom;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoOrganizationDto;

final class TaxcomMapper
{
    /**
     * Ответ GetContacts → EdoOrganizationDto.
     */
    public function toOrganization(array $row): EdoOrganizationDto
    {
        return new EdoOrganizationDto(
            id: (string) ($row['ContactId'] ?? ''),
            name: (string) ($row['Name'] ?? ''),
            inn: (string) ($row['Inn'] ?? ''),
            kpp: $row['Kpp'] ?? null,
            ogrn: $row['Ogrn'] ?? null,
            ediCode: (string) ($row['ContactId'] ?? ''),
            operatorCode: $row['Operator'] ?? null,
        );
    }

    /**
     * Ответ GetDocflowsList → EdoDocumentDto.
     */
    public function toDocument(array $row): EdoDocumentDto
    {
        return new EdoDocumentDto(
            externalId: (string) ($row['DocflowId'] ?? ''),
            externalUuid: null,
            type: $this->mapType($row['DocType'] ?? ''),
            status: $this->mapStatus($row['Status'] ?? ''),
            number: (string) ($row['DocNumber'] ?? ''),
            date: new \DateTimeImmutable(),
            senderName: $row['SenderName'] ?? null,
            receiverName: $row['ReceiverName'] ?? null,
            content: null,
            raw: $row,
        );
    }

    private function mapType(string $taxcomType): EdoDocumentType
    {
        return match ($taxcomType) {
            'Invoice' => EdoDocumentType::UpdSf,
            'CorrectiveInvoice' => EdoDocumentType::UcdCsf,
            'FormalizedStatement' => EdoDocumentType::ActWorks,
            'Nonformalized' => EdoDocumentType::NonFormalized,
            default => EdoDocumentType::NonFormalized,
        };
    }

    private function mapStatus(string $status): EdoDocumentStatus
    {
        return match ($status) {
            'Succeed' => EdoDocumentStatus::Accepted,
            'Failed' => EdoDocumentStatus::Rejected,
            default => EdoDocumentStatus::Unknown,
        };
    }
}
