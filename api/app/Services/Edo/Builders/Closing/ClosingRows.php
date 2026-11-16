<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders\Closing;

use App\DTO\EdoOrganizationDto;
use App\Models\Organization;
use App\Models\RequestDocument;

/**
 * Общие преобразования для билдеров закрывающих документов: строки и итоги
 * берутся из payload документа ЗР (ClosingDocumentService::rows/totals —
 * с агентской скидкой СТО и без «ЗЧ от УК» в документах СТО → УК).
 */
final class ClosingRows
{
    /**
     * @return array<int, array{kind: string, name: string, quantity: float, unit: string, price: float, amount: float, vatRate: float|null, vat: float}>
     */
    public static function rows(RequestDocument $document): array
    {
        return array_values(array_map(static fn (array $row): array => [
            'kind'     => (string) ($row['kind'] ?? 'work'),
            'name'     => (string) ($row['name'] ?? ''),
            'quantity' => (float) ($row['quantity'] ?? 0),
            'unit'     => (string) ($row['unit'] ?? 'шт'),
            'price'    => (float) ($row['price'] ?? 0),
            'amount'   => (float) ($row['amount'] ?? 0),
            'vatRate'  => isset($row['vatRate']) ? (float) $row['vatRate'] : null,
            'vat'      => (float) ($row['vat'] ?? 0),
        ], (array) ($document->payload['rows'] ?? [])));
    }

    public static function vatLabel(?float $rate): string
    {
        return $rate !== null && $rate > 0
            ? rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%'
            : 'без НДС';
    }

    public static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    public static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }

    /** Код единицы измерения по ОКЕИ. */
    public static function okei(string $unit): string
    {
        return match (mb_strtolower(trim($unit))) {
            'н/ч', 'нч', 'ч', 'час', 'чел.ч' => '539',
            'л', 'литр'                       => '112',
            'кг'                              => '166',
            'м'                               => '006',
            'усл', 'усл.', 'услуга'           => '018',
            default                           => '796',
        };
    }

    public static function organization(Organization $org): EdoOrganizationDto
    {
        return new EdoOrganizationDto(
            id: (string) ($org->edo_client_id ?: $org->id),
            name: (string) $org->name,
            inn: (string) $org->inn,
            kpp: (string) ($org->kpp ?? ''),
            ogrn: (string) ($org->ogrn ?? ''),
            ediCode: $org->edo_edi_code,
        );
    }

    public static function fileStem(RequestDocument $document): string
    {
        return str_replace('/', '-', $document->number);
    }
}
