<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Diadoc;

use App\DTO\EdoDocumentDto;
use App\DTO\EdoOrganizationDto;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;

final class DiadocMapper
{
    public function toOrganization(array $row): EdoOrganizationDto
    {
        return new EdoOrganizationDto(
            id: (string) ($row['OrgId'] ?? ''),
            name: (string) ($row['FullName'] ?? ''),
            inn: (string) ($row['Inn'] ?? ''),
            kpp: $row['Kpp'] ?? null,
            ogrn: $row['Ogrn'] ?? null,
            ediCode: $row['Boxes'][0]['BoxId'] ?? null, // Первый ящик как EDI-код
            operatorCode: 'diadoc',
        );
    }

    public function toDocument(array $row): EdoDocumentDto
    {
        return new EdoDocumentDto(
            externalId: (string) ($row['DocumentId'] ?? ''),
            externalUuid: $row['EntityId'] ?? null,
            type: $this->mapDocumentType($row['TypeNamedId'] ?? ''),
            status: $this->mapDocumentStatus($row['DocflowStatus']['PrimaryStatus'] ?? ''),
            number: (string) ($row['DocumentNumber'] ?? ''),
            date: new \DateTimeImmutable($row['DocumentDate'] ?? 'now'),
            senderName: $row['FromDepartmentId'] ?? null,
            receiverName: $row['ToDepartmentId'] ?? null,
            content: null,
            raw: $row,
        );
    }

    private function mapDocumentType(string $typeNamedId): EdoDocumentType
    {
        return match ($typeNamedId) {
            'UniversalTransferDocument' => EdoDocumentType::UpdSfDop,
            'UniversalCorrectionDocument' => EdoDocumentType::UcdCsf,
            'Invoice' => EdoDocumentType::Invoice,
            default => EdoDocumentType::NonFormalized,
        };
    }

    private function mapDocumentStatus(string $primaryStatus): EdoDocumentStatus
    {
        return match ($primaryStatus) {
            'Succeed' => EdoDocumentStatus::Accepted,
            'Failed' => EdoDocumentStatus::Rejected,
            'InProgress' => EdoDocumentStatus::Sended,
            default => EdoDocumentStatus::Unknown,
        };
    }
}
