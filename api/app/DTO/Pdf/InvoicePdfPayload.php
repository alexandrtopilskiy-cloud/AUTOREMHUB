<?php

declare(strict_types=1);

namespace App\DTO\Pdf;

final readonly class InvoicePdfPayload
{
    /**
     * @param PdfRowDto[] $rows
     */
    public function __construct(
        public PdfPartyDto $seller,        // Поставщик (ИП или ООО)
        public string $sellerBankInfo,     // БИК, кор. счет, р/с (можно отформатировать строкой)
        public PdfPartyDto $buyer,         // Покупатель
        public string $basis,              // Основание (ЗН-2402 от 30.10.2026)
        public array $rows,                // Позиции
        public string $totalSumFormatted,  // Итого
        public string $totalSumInWords,    // Сумма прописью
        public string $vatInfo,            // "Без НДС" или "В том числе НДС: ..."
        public string $director,           // Руководитель
        public string $accountant,         // Бухгалтер
    ) {}
}
