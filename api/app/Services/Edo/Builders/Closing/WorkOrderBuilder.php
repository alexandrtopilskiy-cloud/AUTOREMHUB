<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders\Closing;

use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestDocument;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;

/**
 * Заказ-наряд закрытия ЗР (ZN-xxxxxx/1 СТО → УК, ZN-xxxxxx/2 УК → Заказчик):
 * PDF по шаблону feature/sbis_edo и неформализованный документ для ЭДО.
 */
final class WorkOrderBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator,
    ) {}

    public function pdf(RepairRequest $request, RequestDocument $document, Organization $sender, Organization $receiver): string
    {
        return $this->generator->generateClosingWorkOrder($this->viewData($request, $document, $sender, $receiver));
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(RepairRequest $request, RequestDocument $document, Organization $sender, Organization $receiver): array
    {
        $request->loadMissing(['vehicleRecord', 'reasonRecord']);
        $works = [];
        $parts = [];
        $totalWorks = 0.0;
        $totalParts = 0.0;
        $vat = 0.0;

        foreach (ClosingRows::rows($document) as $row) {
            $line = [
                'name'     => $row['name'],
                'sku'      => null,
                'quantity' => $row['quantity'],
                'unit'     => $row['unit'],
                'price'    => $row['price'],
                'amount'   => $row['amount'],
            ];
            $vat += $row['vat'];

            if ($row['kind'] === 'work') {
                $works[] = $line;
                $totalWorks += $row['amount'];
            } else {
                $parts[] = $line;
                $totalParts += $row['amount'];
            }
        }

        return [
            'title'           => 'Заказ-наряд',
            'doc'             => $document,
            'sender'          => $sender,
            'receiver'        => $receiver,
            'request'         => $request,
            'vehicle'         => $request->vehicleRecord,
            'mileage'         => $document->payload['request']['mileage'] ?? ($request->data['mileage'] ?? null),
            'reason'          => trim(implode(': ', array_filter([
                (string) ($request->reasonRecord?->name ?? ''),
                trim((string) ($request->data['description'] ?? '')),
            ], static fn (string $value): bool => $value !== ''))),
            'recommendations' => '',
            'works'           => $works,
            'parts'           => $parts,
            'totalWorks'      => $totalWorks,
            'totalParts'      => $totalParts,
            'totalSum'        => $totalWorks + $totalParts,
            'vatText'         => $vat > 0 ? 'в т. ч. НДС ' . ClosingRows::money($vat) : 'Без НДС',
            'agentDiscount'   => $document->payload['agentDiscount'] ?? null,
        ];
    }

    public function request(RequestDocument $document, string $pdf, Organization $sender, Organization $receiver, ?string $externalId): EdoStructuredRequest
    {
        return new EdoStructuredRequest(
            type: EdoDocumentType::WorkOrder,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: ClosingRows::organization($sender),
            receiver: ClosingRows::organization($receiver),
            items: [],
            totalSum: number_format((float) $document->amount, 2, '.', ''),
            vatSum: number_format((float) $document->vat_amount, 2, '.', ''),
            vatIncluded: true,
            meta: [
                'external_id'  => $externalId,
                'file_name'    => 'Заказ-наряд ' . ClosingRows::fileStem($document) . '.pdf',
                'file_content' => base64_encode($pdf),
                'note'         => 'Заказ-наряд по заявке на ремонт ' . ($document->payload['request']['number'] ?? ''),
            ],
        );
    }
}
