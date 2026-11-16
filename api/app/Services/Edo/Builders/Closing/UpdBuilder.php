<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders\Closing;

use App\DTO\EdoInvoicePayload;
use App\DTO\EdoInvoiceRow;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\UpdFunction;
use App\Models\Organization;
use App\Models\RequestDocument;
use App\Services\Edo\Xml\Upd503Builder;
use DateTimeImmutable;

/**
 * УПД закрытия ЗР (UPD-xxxxxx/1 СТО → УК, UPD-xxxxxx/2 УК → Заказчик):
 * формализованный XML формата 5.03 (СЧФДОП, проверка по XSD) — из feature/sbis_edo.
 * Печатная форма УПД в ЗР остаётся прежней (pdf.closing.upd).
 */
final class UpdBuilder
{
    public function __construct(
        private readonly Upd503Builder $builder,
    ) {}

    /**
     * @return array{xml: string, fileId: string}
     */
    public function xml(RequestDocument $document, Organization $sender, Organization $receiver): array
    {
        $payload = new EdoInvoicePayload(
            seller: $this->party($sender),
            buyer: $this->party($receiver),
            rows: $this->rows($document),
        );

        $xml = $this->builder->build(
            payload: $payload,
            function: UpdFunction::SfDop,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            signer: EdoSignerDto::fromOrganization($sender),
        );

        return ['xml' => $xml, 'fileId' => $this->builder->getLastGeneratedIdFile()];
    }

    /**
     * @param array{xml: string, fileId: string} $xml
     */
    public function request(RequestDocument $document, array $xml, Organization $sender, Organization $receiver, ?string $externalId): EdoStructuredRequest
    {
        return new EdoStructuredRequest(
            type: EdoDocumentType::UpdSfDop,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: ClosingRows::organization($sender),
            receiver: ClosingRows::organization($receiver),
            items: [],
            totalSum: number_format((float) $document->amount, 2, '.', ''),
            vatSum: number_format((float) $document->vat_amount, 2, '.', ''),
            vatIncluded: true,
            meta: [
                'external_id' => $externalId,
                'xml_content' => $xml['xml'],
                'file_name'   => ($xml['fileId'] !== '' ? $xml['fileId'] : ClosingRows::fileStem($document)) . '.xml',
            ],
        );
    }

    private function party(Organization $org): EdoPartyInfo
    {
        $isIp = strlen((string) $org->inn) === 12;

        return new EdoPartyInfo(
            name: $isIp && $org->director_name ? (string) $org->director_name : (string) $org->name,
            inn: (string) $org->inn,
            kpp: $isIp ? '' : (string) ($org->kpp ?? ''),
            address: $org->legal_address ?: null,
            regionCode: null,
            ediCode: $org->edo_edi_code,
            isIndividual: $isIp,
            ogrnip: $isIp ? ($org->ogrn ?: null) : null,
        );
    }

    /**
     * @return array<int, EdoInvoiceRow>
     */
    private function rows(RequestDocument $document): array
    {
        $result = [];

        foreach (ClosingRows::rows($document) as $row) {
            $total = round($row['amount'], 2);
            $vat = round($row['vat'], 2);
            $net = round($total - $vat, 2);
            $quantity = $row['quantity'] > 0 ? $row['quantity'] : 1.0;

            $result[] = new EdoInvoiceRow(
                name: $row['name'],
                unit: ClosingRows::okei($row['unit']),
                amount: rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'),
                price: number_format($net / $quantity, 2, '.', ''),
                netSum: number_format($net, 2, '.', ''),
                vatSum: number_format($vat, 2, '.', ''),
                totalSum: number_format($total, 2, '.', ''),
                vatType: ClosingRows::vatLabel($row['vatRate']),
            );
        }

        return $result;
    }
}
