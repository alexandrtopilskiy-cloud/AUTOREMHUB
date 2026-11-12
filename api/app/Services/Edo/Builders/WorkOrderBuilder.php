<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\DTO\Pdf\PdfPartyDto;
use App\DTO\Pdf\PdfRowDto;
use App\DTO\Pdf\PdfTotalsDto;
use App\DTO\Pdf\WorkOrderPdfPayload;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\WorkOrder;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

final class WorkOrderBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator
    ) {}

    public function build(
        RepairRequest $req,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver
    ): EdoStructuredRequest {
        $logger = Log::channel('edo');
        $logger->info('START BUILD');
        $workOrder = $req->workOrder;
        if (!$workOrder) {
            throw new \RuntimeException("У заявки {$req->number} отсутствует заказ-наряд.");
        }

        // 1. Генерируем PDF
        $pdfContent = $this->generator->generateWorkOrder($req, $workOrder, $sender, $receiver);

        return new EdoStructuredRequest(
            type: EdoDocumentType::NonFormalized, // <-- Указываем, что это неформализованный документ
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
            totalSum: number_format((float) $workOrder->total, 2, '.', ''),
            vatSum: '0.00',
            vatIncluded: true,
            meta: [
                // Для неформализованного документа xml_content не нужен
                'file_name'         => "Заказ-наряд_{$document->number}.pdf",
                'file_content'      => base64_encode($pdfContent),
                'repair_request_id' => $req->id,
                'uploaded_by'       => $req->created_by,
            ],
        );
    }

    public function preparePayload(
        RepairRequest $req,
        WorkOrder $workOrder,
        Organization $sender,
        Organization $receiver
    ): WorkOrderPdfPayload {

        $rows = [];
        $totalAmount = 0;
        $totalVat = 0;
        $vatRate = 20.0;

        // 1. Формируем строки таблицы
        foreach ($workOrder->items as $item) {
            $quantity = (float) $item->quantity;
            $price = (float) $item->price;
            $amount = $quantity * $price;

            // Вычисляем НДС (если цена с НДС)
            $vatAmount = $amount - ($amount / (1 + $vatRate / 100));

            $totalAmount += $amount;
            $totalVat += $vatAmount;

            $rows[] = new PdfRowDto(
                name: $item->name,
                quantityFormatted: rtrim(rtrim(number_format($quantity, 3, ',', ' '), '0'), ','),
                unit: 'шт', // Или берем из $item->unit_code
                priceFormatted: number_format($price, 2, ',', ' '),
                vatLabel: '20%',
                amountFormatted: number_format($amount, 2, ',', ' '),
            );
        }

        // 2. Формируем данные сторон
        $sellerDto = new PdfPartyDto(
            displayName: "{$sender->name}, ИНН {$sender->inn}, КПП {$sender->kpp}, {$sender->legal_address}",
            director: $sender->director_name
        );

        $buyerDto = new PdfPartyDto(
            displayName: "{$receiver->name}, ИНН {$receiver->inn}, КПП {$receiver->kpp}, {$receiver->legal_address}",
            director: $receiver->director_name
        );

        // 3. Информация о ТС
        $vehicle = $req->vehicleRecord;
        $vehicleInfo = "ТС: " . ($vehicle->model ?? '—')
        . " · Госномер " . ($vehicle->plate ?? '—')
        . " · VIN " . ($vehicle->vin ?? '—');

        // 4. Итоги
        $totalsDto = new PdfTotalsDto(
            amountFormatted: number_format($totalAmount, 2, ',', ' '),
            vatFormatted: number_format($totalVat, 2, ',', ' ')
        );

        // 5. Возвращаем DTO
        return new WorkOrderPdfPayload(
            seller: $sellerDto,
            buyer: $buyerDto,
            vehicleInfo: $vehicleInfo,
            rows: $rows,
            totals: $totalsDto,
        );
    }
}
