<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoInvoicePayload;
use App\DTO\EdoInvoiceRow;
use App\DTO\EdoOrganizationDto;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\UpdFunction;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Services\DocumentGeneratorService;
use App\Services\Edo\Xml\Upd503Builder;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

final class UpdBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator,
        private readonly Upd503Builder $builder
    ) {}

    /**
     * @param Organization $sender Организация-отправитель (СТО или УК)
     * @param Organization $receiver Организация-получатель (УК или Заказчик)
     */
    public function build(
        RepairRequest $req,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver
    ): EdoStructuredRequest {
        $logger = Log::channel('edo');

        $workOrder = $req->workOrder;

        if (!$workOrder) {
            throw new \RuntimeException("У заявки {$req->number} отсутствует заказ-наряд.");
        }

        // 1. Формируем Payload для XML билдера (с НДС)
        $payload = new EdoInvoicePayload(
            seller: new EdoPartyInfo(
                name: $sender->name,
                inn: $sender->inn,
                kpp: $sender->kpp ?? '',
                address: $sender->legal_address,
                ediCode: $sender->edo_edi_code
            ),
            buyer: new EdoPartyInfo(
                name: $receiver->name,
                inn: $receiver->inn,
                kpp: $receiver->kpp ?? '',
                address: $receiver->legal_address,
                ediCode: $receiver->edo_edi_code
            ),
            rows: $this->mapWorkOrderToRows($workOrder)
        );

        $fio = $this->parseFio($sender->director_name);

        // 2. Формируем данные подписанта (от имени ОТПРАВИТЕЛЯ)
        $signer = new EdoSignerDto(
            lastName: $fio['lastName'],
            firstName: $fio['firstName'],
            middleName: $fio['middleName'],
            title: 'Генеральный директор',
            inn: $sender->inn,
            authorityScope: '1',
            signatureType: '1'
        );

        // 3. Генерируем XML (СЧФДОП — Статус 1)
        $xmlContent = $this->builder->build(
            payload: $payload,
            function: UpdFunction::SfDop,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            signer: $signer
        );

        // 4. Считаем общую сумму
        $totalSum = number_format((float) $workOrder->total, 2, '.', '');

        return new EdoStructuredRequest(
            type: EdoDocumentType::UpdSfDop,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                id: $sender->uuid,
                name: $sender->name,
                inn: $sender->inn,
                kpp: $sender->kpp ?? '',
                login: $sender->edo_client_id,
                password: $sender->edo_refresh_token,
            ),
            receiver: new EdoOrganizationDto(
                id: $receiver->uuid,
                name: $receiver->name,
                inn: $receiver->inn,
                kpp: $receiver->kpp ?? '',
            ),
            items: [],
            totalSum: $totalSum,
            vatSum: '0.00', // Если нужно, можно посчитать точнее
            vatIncluded: true,
            meta: [
                'xml_content'       => $xmlContent,
                'file_name'         => "УПД_{$document->number}.pdf",
                'repair_request_id' => $req->id,
                'uploaded_by'       => $req->created_by,
            ],
        );
    }

    private function mapWorkOrderToRows($workOrder): array
    {
        $rows = [];
        foreach ($workOrder->items as $item) {
            $totalSum = (float) $item->total;
            $vatRate = 20.0;

            $netSum = $totalSum / (1 + $vatRate / 100);
            $vatSum = $totalSum - $netSum;

            $rows[] = new EdoInvoiceRow(
                name: $item->name,
                unit: $item->unit_code ?? '796',
                amount: (string) $item->quantity,
                price: number_format($netSum / max(1, (float)$item->quantity), 2, '.', ''),
                netSum: number_format($netSum, 2, '.', ''),
                vatType: '20%',
                vatSum: number_format($vatSum, 2, '.', ''),
                totalSum: number_format($totalSum, 2, '.', '')
            );
        }
        return $rows;
    }

    private function parseFio(string $fullName): array
    {
        $parts = explode(' ', trim($fullName));
        return [
            'lastName'   => $parts[0] ?? '',
            'firstName'  => $parts[1] ?? '',
            'middleName' => $parts[2] ?? null,
        ];
    }
}
