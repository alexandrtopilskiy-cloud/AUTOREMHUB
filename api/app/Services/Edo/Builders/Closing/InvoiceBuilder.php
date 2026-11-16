<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders\Closing;

use App\DTO\EdoStructuredRequest;
use App\DTO\Pdf\InvoicePdfPayload;
use App\DTO\Pdf\PdfPartyDto;
use App\DTO\Pdf\PdfRowDto;
use App\Enums\Edo\EdoDocumentType;
use App\Models\Organization;
use App\Models\RequestDocument;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;

/**
 * Счёт на оплату закрытия ЗР (SCH-xxxxxx/1 СТО → УК, SCH-xxxxxx/2 УК → Заказчик):
 * PDF по шаблону feature/sbis_edo и неформализованный документ для ЭДО.
 */
final class InvoiceBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator,
    ) {}

    public function pdf(RequestDocument $document, Organization $sender, Organization $receiver): string
    {
        return $this->generator->generateClosingInvoice(
            $this->payload($document, $sender, $receiver),
            $document->number,
            $document->document_date,
        );
    }

    public function payload(RequestDocument $document, Organization $sender, Organization $receiver): InvoicePdfPayload
    {
        $rows = [];
        $total = 0.0;
        $vat = 0.0;

        foreach (ClosingRows::rows($document) as $row) {
            $total += $row['amount'];
            $vat += $row['vat'];
            $rows[] = new PdfRowDto(
                name: $row['name'],
                quantityFormatted: ClosingRows::quantity($row['quantity']),
                unit: $row['unit'],
                priceFormatted: ClosingRows::money($row['price']),
                vatLabel: ClosingRows::vatLabel($row['vatRate']),
                amountFormatted: ClosingRows::money($row['amount']),
            );
        }

        $director = (string) ($sender->director_name ?? '');

        return new InvoicePdfPayload(
            seller: new PdfPartyDto(
                displayName: $this->display($sender),
                director: $director,
                name: (string) $sender->name,
                inn: (string) $sender->inn,
                kpp: $sender->kpp,
                bik: $sender->bik,
                correspondent_account: $sender->correspondent_account,
                settlement_account: $sender->settlement_account,
            ),
            sellerBankInfo: trim("БИК {$sender->bik}, Кор. сч. {$sender->correspondent_account}, Р/с {$sender->settlement_account}"),
            buyer: new PdfPartyDto(
                displayName: $this->display($receiver),
                director: (string) ($receiver->director_name ?? ''),
            ),
            basis: (string) (($document->payload['basis'] ?? '') ?: 'Заявка на ремонт № ' . ($document->payload['request']['number'] ?? '')),
            rows: $rows,
            totalSumFormatted: ClosingRows::money($total),
            totalSumInWords: self::sumInWords($total),
            vatInfo: $vat > 0 ? ClosingRows::money($vat) : 'Без НДС',
            director: $director,
            accountant: $director,
            bankName: (string) ($sender->bank_name ?? ''),
        );
    }

    public function request(RequestDocument $document, string $pdf, Organization $sender, Organization $receiver, ?string $externalId): EdoStructuredRequest
    {
        return new EdoStructuredRequest(
            type: EdoDocumentType::NonFormalized,
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
                'file_name'    => 'Счёт на оплату ' . ClosingRows::fileStem($document) . '.pdf',
                'file_content' => base64_encode($pdf),
                'note'         => 'Счёт на оплату по заявке на ремонт ' . ($document->payload['request']['number'] ?? ''),
            ],
        );
    }

    private function display(Organization $org): string
    {
        $isIp = strlen((string) $org->inn) === 12;

        return implode(', ', array_filter([
            (string) $org->name,
            $org->inn ? 'ИНН ' . $org->inn : '',
            !$isIp && $org->kpp ? 'КПП ' . $org->kpp : '',
            $org->ogrn ? ($isIp ? 'ОГРНИП ' : 'ОГРН ') . $org->ogrn : '',
            (string) ($org->legal_address ?? ''),
        ], static fn (string $value): bool => $value !== ''));
    }

    /** Сумма прописью: «Одна тысяча двести рублей 50 копеек». */
    public static function sumInWords(float $amount): string
    {
        $rub = (int) floor(round($amount, 2));
        $kop = (int) round((round($amount, 2) - $rub) * 100);

        $words = class_exists(\NumberFormatter::class)
            ? (string) (new \NumberFormatter('ru', \NumberFormatter::SPELLOUT))->format($rub)
            : (string) $rub;
        $words = mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1);

        return sprintf('%s %s %02d %s', $words, self::plural($rub, 'рубль', 'рубля', 'рублей'), $kop, self::plural($kop, 'копейка', 'копейки', 'копеек'));
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $last = $n % 10;

        return match (true) {
            $n > 10 && $n < 20 => $many,
            $last === 1        => $one,
            $last >= 2 && $last <= 4 => $few,
            default            => $many,
        };
    }
}
