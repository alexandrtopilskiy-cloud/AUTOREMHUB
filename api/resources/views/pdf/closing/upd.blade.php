{{--
    Универсальный передаточный документ — печатная форма по Приложению № 1
    к постановлению Правительства РФ от 26.12.2011 № 1137 (ред. от 23.01.2026 № 26),
    альбомный A4. Данные — из payload документа ЗР (ClosingDocumentService),
    отметка ЭДО ($edo) — внизу, если документ передан оператору.
--}}
@php
    $seller = $payload['seller'] ?? [];
    $buyer = $payload['buyer'] ?? [];
    $rows = array_values((array) ($payload['rows'] ?? []));
    $money = fn ($value) => number_format((float) $value, 2, '.', ' ');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $vatLabel = fn ($rate) => $rate !== null && (float) $rate > 0
        ? rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.') . '%'
        : 'без НДС';
    $okei = fn (string $unit) => \App\Services\Edo\Builders\Closing\ClosingRows::okei($unit);
    $innKpp = fn (array $party) => trim(($party['inn'] ?? '') . (($party['kpp'] ?? '') !== '' ? '/' . $party['kpp'] : ''));
    $nameAddress = fn (array $party) => trim(($party['name'] ?? '') . (($party['address'] ?? '') !== '' ? ', ' . $party['address'] : ''));
    $bank = fn (array $party) => ($party['account'] ?? '') !== ''
        ? trim('Р/с ' . $party['account'] . ' в ' . ($party['bank'] ?? '') . (($party['bik'] ?? '') !== '' ? ', БИК ' . $party['bik'] : '') . (($party['corrAccount'] ?? '') !== '' ? ', к/с ' . $party['corrAccount'] : ''))
        : '';
    $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    $date = $doc->document_date;
    $sumNoVat = 0.0;
    $sumVat = 0.0;
    $sumTotal = 0.0;
    $hasVat = false;
    foreach ($rows as $row) {
        $sumVat += (float) ($row['vat'] ?? 0);
        $sumTotal += (float) ($row['amount'] ?? 0);
        $hasVat = $hasVat || ((float) ($row['vatRate'] ?? 0) > 0);
    }
    $sumNoVat = $sumTotal - $sumVat;
    $isIp = fn (array $party) => strlen((string) ($party['inn'] ?? '')) === 12;
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>УПД № {{ $doc->number }}</title>
    <style>
        @page { margin: 7mm 8mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7px; color: #000; line-height: 1.25; }
        table { border-collapse: collapse; width: 100%; }
        td, th { padding: 0; vertical-align: top; }
        .head td { padding: 0.5px 2px; }
        .u { border-bottom: 0.6px solid #000; }
        .num { text-align: right; white-space: nowrap; }
        .c { text-align: center; }
        .b { font-weight: bold; }
        .cap { font-size: 5.5px; text-align: center; color: #000; }
        .ref { width: 22px; text-align: right; }
        .grid th, .grid td { border: 0.6px solid #000; padding: 1px 2px; }
        .grid th { font-weight: normal; text-align: center; vertical-align: middle; font-size: 6.5px; }
        .grid td { font-size: 7px; }
        .sig td { padding: 1px 3px; vertical-align: bottom; }
        .box { border: 1px solid #000; display: inline-block; padding: 1px 8px; font-weight: bold; }
    </style>
</head>
<body>
<table>
    <tr>
        <td style="width: 13%; border-right: 1.5px solid #000; padding-right: 4px;">
            Универсальный<br>передаточный<br>документ<br><br>
            Статус: <span class="box">{{ $payload['updStatus'] ?? 1 }}</span><br><br>
            1 – счет-фактура и передаточный документ (акт)<br>
            2 – передаточный документ (акт)
        </td>
        <td style="padding-left: 5px;">
            <table>
                <tr>
                    <td style="font-size: 11px;" class="b">Счет-фактура № {{ $doc->number }} от {{ $date->format('d') }} {{ $months[(int) $date->format('n')] }} {{ $date->format('Y') }} г.</td>
                    <td style="font-size: 6.5px; text-align: right;">Приложение N 1 к постановлению Правительства Российской Федерации от 26.12.2011 №1137<br>(в ред. Постановления Правительства РФ от 23.01.2026 № 26)</td>
                </tr>
                <tr><td colspan="2">Исправление № --- от ---</td></tr>
            </table>
            <table class="head">
                <tr><td style="width: 25%;" class="b">Продавец</td><td class="u b">{{ $seller['name'] ?? '' }}</td><td class="ref">(2)</td></tr>
                <tr><td>Адрес</td><td class="u">{{ $seller['address'] ?? '' }}</td><td class="ref">(2а)</td></tr>
                <tr><td>ИНН/КПП продавца</td><td class="u">{{ $innKpp($seller) }}</td><td class="ref">(2б)</td></tr>
                <tr><td>Грузоотправитель и его адрес:</td><td class="u">--</td><td class="ref">(3)</td></tr>
                <tr><td>Грузополучатель и его адрес:</td><td class="u">--</td><td class="ref">(4)</td></tr>
                <tr><td>К платежно-расчетному документу</td><td class="u">-</td><td class="ref">(5)</td></tr>
                <tr><td>Документ об отгрузке:</td><td class="u">№ п/п 1–{{ count($rows) }} {{ $payload['basis'] ?? '' }}</td><td class="ref">(5а)</td></tr>
                <tr><td class="b">Покупатель</td><td class="u b">{{ $buyer['name'] ?? '' }}</td><td class="ref">(6)</td></tr>
                <tr><td>Адрес</td><td class="u">{{ $buyer['address'] ?? '' }}</td><td class="ref">(6а)</td></tr>
                <tr><td>ИНН/КПП покупателя</td><td class="u">{{ $innKpp($buyer) }}</td><td class="ref">(6б)</td></tr>
                <tr><td>Валюта: наименование, код</td><td class="u">Российский рубль, код - 643</td><td class="ref">(7)</td></tr>
                <tr><td colspan="2" class="u">Идентификатор государственного контракта, договора (соглашения) (при наличии)</td><td class="ref">(8)</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="grid" style="margin-top: 5px;">
    <tr>
        <th rowspan="2" style="width: 6%;">Код товара/ работ, услуг</th>
        <th rowspan="2" style="width: 2%;">№ п/п</th>
        <th rowspan="2" style="width: 19%;">Наименование товара (описание выполненных работ, оказанных услуг), имущественного права</th>
        <th rowspan="2" style="width: 4%;">Код вида товара</th>
        <th colspan="2">Единица измерения</th>
        <th rowspan="2" style="width: 4.5%;">Коли-чество (объем)</th>
        <th rowspan="2" style="width: 6%;">Цена (тариф) за единицу измерения</th>
        <th rowspan="2" style="width: 7%;">Стоимость товаров (работ, услуг), имущественных прав без налога - всего</th>
        <th rowspan="2" style="width: 4.5%;">В том числе сумма акциза</th>
        <th rowspan="2" style="width: 4%;">Нало-говая ставка</th>
        <th rowspan="2" style="width: 6%;">Сумма налога, предъяв-ляемая покупателю</th>
        <th rowspan="2" style="width: 7%;">Стоимость товаров (работ, услуг), имущественных прав с налогом - всего</th>
        <th colspan="2">Страна происхождения товара</th>
        <th rowspan="2" style="width: 8%;">Регистрационный номер декларации на товары или регистрационный номер партии товара, подлежащего прослеживаемости</th>
    </tr>
    <tr>
        <th style="width: 2.5%;">код</th>
        <th style="width: 4.5%;">условное обозначе-ние (нацио-нальное)</th>
        <th style="width: 3%;">Циф-ровой код</th>
        <th style="width: 4%;">Краткое наиме-нование</th>
    </tr>
    <tr>
        @foreach (['А', '1', '1а', '1б', '2', '2а', '3', '4', '5', '6', '7', '8', '9', '10', '10а', '11'] as $column)
            <th>{{ $column }}</th>
        @endforeach
    </tr>
    @forelse ($rows as $i => $row)
        @php
            $amount = (float) ($row['amount'] ?? 0);
            $vat = (float) ($row['vat'] ?? 0);
            $quantity = (float) ($row['quantity'] ?? 0);
            $net = $amount - $vat;
            $unit = (string) ($row['unit'] ?? 'шт');
        @endphp
        <tr>
            <td class="c">-</td>
            <td class="c">{{ $i + 1 }}</td>
            <td>{{ $row['name'] ?? '' }}</td>
            <td class="c">-</td>
            <td class="c">{{ $okei($unit) }}</td>
            <td class="c">{{ $unit }}</td>
            <td class="num">{{ $qty($quantity) }}</td>
            <td class="num">{{ $money($quantity > 0 ? $net / $quantity : $net) }}</td>
            <td class="num">{{ $money($net) }}</td>
            <td class="c">без акциза</td>
            <td class="c">{{ $vatLabel($row['vatRate'] ?? null) }}</td>
            <td class="num">{{ ($row['vatRate'] ?? 0) > 0 ? $money($vat) : 'без НДС' }}</td>
            <td class="num">{{ $money($amount) }}</td>
            <td class="c">-</td>
            <td class="c">-</td>
            <td class="c">-</td>
        </tr>
    @empty
        <tr><td colspan="16" class="c">Нет позиций</td></tr>
    @endforelse
    <tr>
        <td colspan="8" class="b" style="border-left: none; border-bottom: none;">&nbsp;&nbsp;&nbsp;Всего к оплате</td>
        <td class="num b">{{ $money($sumNoVat) }}</td>
        <td colspan="2" class="c">X</td>
        <td class="num">{{ $hasVat ? $money($sumVat) : 'без НДС' }}</td>
        <td class="num b">{{ $money($sumTotal) }}</td>
        <td colspan="3" style="border: none;"></td>
    </tr>
</table>

<table style="margin-top: 3px;">
    <tr>
        <td style="width: 9%; border-right: 1.5px solid #000; border-bottom: 1.5px solid #000;">Документ<br>составлен на<br>___ листах</td>
        <td style="border-bottom: 1.5px solid #000; padding-left: 4px;">
            <table class="sig">
                <tr>
                    <td style="width: 18%;">Руководитель организации<br>или иное уполномоченное лицо</td>
                    <td class="u" style="width: 12%;"></td>
                    <td class="u c" style="width: 17%;">{{ $isIp($seller) ? '' : ($seller['director'] ?? '') }}</td>
                    <td style="width: 16%; padding-left: 10px;">Главный бухгалтер<br>или иное уполномоченное лицо</td>
                    <td class="u" style="width: 12%;"></td>
                    <td class="u" style="width: 17%;"></td>
                </tr>
                <tr><td></td><td class="cap">(подпись)</td><td class="cap">(ф.и.о.)</td><td></td><td class="cap">(подпись)</td><td class="cap">(ф.и.о.)</td></tr>
                <tr>
                    <td>Индивидуальный предприниматель<br>или иное уполномоченное лицо</td>
                    <td class="u"></td>
                    <td class="u c">{{ $isIp($seller) ? ($seller['director'] ?? '') : '' }}</td>
                    <td colspan="3" class="u c">{{ $isIp($seller) ? ($seller['ogrn'] ?? '') : '' }}</td>
                </tr>
                <tr><td></td><td class="cap">(подпись)</td><td class="cap">(ф.и.о.)</td><td colspan="3" class="cap">(основной государственный регистрационный номер индивидуального предпринимателя и дата присвоения такого номера)</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="sig" style="margin-top: 4px;">
    <tr><td style="width: 21%;">Основание передачи (сдачи)/получения приемки</td><td class="u">{{ $payload['basis'] ?? '' }}</td><td class="ref">(8)</td></tr>
    <tr><td></td><td class="cap">(договор; доверенность и др.)</td><td></td></tr>
    <tr><td>Данные о транспортировке и грузе</td><td class="u"></td><td class="ref">(9)</td></tr>
    <tr><td></td><td class="cap">(транспортная накладная, поручение экспедитору, экспедиторская/складская расписка и др./масса нетто/брутто груза, если не приведены ссылки на транспортные документы, содержащие эти сведения)</td><td></td></tr>
</table>

<table style="margin-top: 2px;">
    <tr>
        @foreach ([['party' => $seller, 'title' => 'Товар (груз) передал/услуги, результаты работ, права сдал', 'date' => 'Дата отгрузки, передачи (сдачи)', 'other' => 'Иные сведения об отгрузке, передаче', 'otherCap' => '(ссылки на неотъемлемые приложения, сопутствующие документы, иные документы и т. п.)', 'bankCap' => '(банковские реквизиты продавца)', 'author' => 'Наименование экономического субъекта — составителя документа (в т. ч. комиссионера/агента)', 'n' => [10, 11, 12, 13, 14, 15, 16]], ['party' => $buyer, 'title' => 'Товар (груз) получил/услуги, результаты работ, права принял', 'date' => 'Дата получения (приемки)', 'other' => 'Иные сведения о получении, приемке', 'otherCap' => '(информация о наличии/отсутствии претензии; ссылки на неотъемлемые приложения и другие документы и т. п.)', 'bankCap' => '(банковские реквизиты покупателя)', 'author' => 'Наименование экономического субъекта — составителя документа', 'n' => [17, 18, 19, 20, 21, 22, 23]]] as $index => $side)
            <td style="width: 50%; {{ $index === 0 ? 'border-right: 0.8px solid #000; padding-right: 5px;' : 'padding-left: 5px;' }}">
                <table class="sig">
                    <tr><td colspan="4">{{ $side['title'] }}</td></tr>
                    <tr>
                        <td class="u c" style="width: 32%;">{{ $index === 0 ? ($isIp($side['party']) ? 'Индивидуальный предприниматель' : 'Руководитель') : '' }}</td>
                        <td class="u" style="width: 22%;"></td>
                        <td class="u c" style="width: 38%;">{{ $index === 0 ? ($side['party']['director'] ?? '') : '' }}</td>
                        <td class="ref">({{ $side['n'][0] }})</td>
                    </tr>
                    <tr><td class="cap">(должность)</td><td class="cap">(подпись)</td><td class="cap">(ф.и.о.)</td><td></td></tr>
                    <tr>
                        <td>{{ $side['date'] }}</td>
                        <td colspan="2">@if ($index === 0)« {{ $date->format('d') }} » {{ $months[(int) $date->format('n')] }} {{ $date->format('Y') }} г.@else«____» ______________ 20___ г.@endif</td>
                        <td class="ref">({{ $side['n'][1] }})</td>
                    </tr>
                    <tr><td colspan="3">{{ $side['other'] }}</td><td></td></tr>
                    <tr><td colspan="3" class="u">{{ $index === 0 ? ('Заявка на ремонт ' . ($payload['request']['number'] ?? '') . (($payload['request']['vehicle'] ?? '') !== '' ? ', ТС ' . $payload['request']['vehicle'] : '') . (($payload['request']['plate'] ?? '') !== '' ? ', г/н ' . $payload['request']['plate'] : '')) : '' }}</td><td class="ref">({{ $side['n'][2] }})</td></tr>
                    <tr><td colspan="3" class="cap">{{ $side['otherCap'] }}</td><td></td></tr>
                    <tr><td colspan="3" class="u">{{ $bank($side['party']) }}</td><td class="ref">({{ $side['n'][3] }})</td></tr>
                    <tr><td colspan="3" class="cap">{{ $side['bankCap'] }}</td><td></td></tr>
                    <tr><td colspan="4">Ответственный за правильность оформления факта хозяйственной жизни</td></tr>
                    <tr>
                        <td class="u"></td><td class="u"></td><td class="u c">{{ $index === 0 ? ($side['party']['director'] ?? '') : '' }}</td>
                        <td class="ref">({{ $side['n'][4] }})</td>
                    </tr>
                    <tr><td class="cap">(должность)</td><td class="cap">(подпись)</td><td class="cap">(ф.и.о.)</td><td></td></tr>
                    <tr><td colspan="4">{{ $side['author'] }}</td></tr>
                    <tr><td colspan="3" class="u">{{ ($side['party']['name'] ?? '') }}{{ $innKpp($side['party']) !== '' ? ', ИНН/КПП ' . $innKpp($side['party']) : '' }}</td><td class="ref">({{ $side['n'][5] }})</td></tr>
                    <tr><td colspan="3" class="cap">(может не заполняться при проставлении печати в М. П., может быть указан ИНН/КПП)</td><td></td></tr>
                    <tr><td colspan="4" style="padding-left: 40px;">М.П.</td></tr>
                </table>
            </td>
        @endforeach
    </tr>
</table>

@include('pdf.closing._edo_stamp')
</body>
</html>
