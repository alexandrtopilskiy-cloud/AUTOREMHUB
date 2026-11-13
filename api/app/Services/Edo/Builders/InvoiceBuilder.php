<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\DTO\Pdf\InvoicePdfPayload;
use App\DTO\Pdf\PdfPartyDto;
use App\DTO\Pdf\PdfRowDto;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\WorkOrder;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;

final class InvoiceBuilder
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

        $workOrder = $req->workOrder;
        if (!$workOrder) {
            throw new \RuntimeException("У заявки {$req->number} отсутствует заказ-наряд.");
        }

        // 1. Формируем DTO для шаблона
        $payload = $this->preparePayload($req, $workOrder, $sender, $receiver);

        // 2. Генерируем PDF
        $pdfContent = $this->generator->generateInvoice(
            $payload,
            $document->number,
            $document->document_date
        );

        // 3. Возвращаем запрос для отправки в ЭДО
        return new EdoStructuredRequest(
            type: EdoDocumentType::NonFormalized, // Счет — неформализованный
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
                'file_name'         => "Счет_{$document->number}.pdf",
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
    ): InvoicePdfPayload {

        $rows = [];
        $totalAmount = 0;
        $totalVat = 0;
        $vatRate = 20.0;

        foreach ($workOrder->items as $item) {
            $quantity = (float) $item->quantity;
            $price = (float) $item->price;
            $amount = $quantity * $price;
            $vatAmount = $amount - ($amount / (1 + $vatRate / 100));

            $totalAmount += $amount;
            $totalVat += $vatAmount;

            $rows[] = new PdfRowDto(
                name: $item->name,
                quantityFormatted: rtrim(rtrim(number_format($quantity, 3, ',', ' '), '0'), ','),
                unit: 'шт',
                priceFormatted: number_format($price, 2, ',', ' '),
                vatLabel: '20%',
                amountFormatted: number_format($amount, 2, ',', ' '),
            );
        }

        // --- Продавец (ИП Топильский) ---
        $sellerDto = new PdfPartyDto(
            displayName: "{$sender->name}, ИНН {$sender->inn}, ОГРНИП {$sender->ogrn}, {$sender->legal_address}",
            director: $sender->director_name,
            name: $sender->name,
            inn: $sender->inn,
            kpp: $sender->kpp,
            bik: $sender->bik,
            correspondent_account: $sender->correspondent_account,
            settlement_account: $sender->settlement_account,
        );

        // --- Покупатель (из Organizations) ---
        $buyerDto = new PdfPartyDto(
            displayName: "{$receiver->name}, ИНН {$receiver->inn}, КПП {$receiver->kpp}, ОГРН {$receiver->ogrn}, {$receiver->legal_address}",
            director: $receiver->director_name
        );

        // Банковские реквизиты (можно вынести в отдельный метод)
        $bankInfo = "БИК {$sender->bik}, Кор. сч. {$sender->correspondent_account}, Р/с {$sender->settlement_account}";

        // Сумма прописью (для примера, можно подключить библиотеку)
        $sumInWords = $this->sumInWords($totalAmount);

        return new InvoicePdfPayload(
            seller: $sellerDto,
            sellerBankInfo: $bankInfo,
            buyer: $buyerDto,
            basis: "Заявка на ремонт № {$req->number}", // <-- Изменили основание
            rows: $rows,
            totalSumFormatted: number_format($totalAmount, 2, ',', ' '),
            totalSumInWords: $sumInWords,
            vatInfo: $vatRate > 0 ? 'В том числе НДС: ' . number_format($totalVat, 2, ',', ' ') : 'Без НДС',
            director: $sender->director_name,
            accountant: $sender->director_name,
        );
    }

    private function sumInWords(float $amount): string
    {
        // Разделяем на рубли и копейки
        $rub = floor($amount);
        $kop = round(($amount - $rub) * 100);

        $formatter = new \NumberFormatter('ru', \NumberFormatter::SPELLOUT);
        $str = $formatter->format($rub);
        $str = mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);

        // Склонение слова "рубль"
        $num = $rub % 100;
        if ($num > 19) {
            $num = $num % 10;
        }
        $rubWord = match ($num) {
            1 => 'рубль',
            2, 3, 4 => 'рубля',
            default => 'рублей',
        };

        // Склонение слова "копейка"
        $numKop = $kop % 100;
        if ($numKop > 19) {
            $numKop = $numKop % 10;
        }
        $kopWord = match ($numKop) {
            1 => 'копейка',
            2, 3, 4 => 'копейки',
            default => 'копеек',
        };

        return "{$str} {$rubWord} {$kop} {$kopWord}";
    }
}
