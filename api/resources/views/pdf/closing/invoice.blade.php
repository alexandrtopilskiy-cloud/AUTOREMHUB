<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style type="text/css">
        * { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; line-height: 14px; }
        table { margin: 0 0 15px 0; width: 100%; border-collapse: collapse; border-spacing: 0; }
        table th { padding: 5px; font-weight: bold; }
        table td { padding: 5px; }
        .header { margin: 0 0 15px 0; font-size: 10px; line-height: 12px; text-align: center; color: #444; }
        h1 { margin: 0 0 15px 0; padding: 10px 0; border-bottom: 2px solid #000; font-weight: bold; font-size: 18px; }

        /* Реквизиты банка */
        .details td { padding: 3px 5px; border: 1px solid #000; font-size: 11px; line-height: 13px; vertical-align: top; }

        /* Поставщик/Покупатель */
        .contract th { padding: 3px 0; vertical-align: top; text-align: left; font-size: 11px; line-height: 14px; font-weight: normal; }
        .contract td { padding: 3px 0; font-weight: bold; width: 100px; }

        /* Таблица товаров */
        .list thead, .list tbody { border: 2px solid #000; }
        .list thead th { padding: 5px 2px; border: 1px solid #000; vertical-align: middle; text-align: center; font-size: 10px; }
        .list tbody td { padding: 3px 4px; border: 1px solid #000; vertical-align: middle; font-size: 10px; line-height: 12px; }
        .list tfoot th { padding: 5px; border: none; text-align: right; font-size: 11px; }

        /* Сумма и подписи */
        .total { margin: 0 0 20px 0; padding: 0 0 10px 0; border-bottom: 2px solid #000; }
        .total p { margin: 0; padding: 0; font-size: 11px; }
        .sign { position: relative; }
        .sign table { width: 60%; }
        .sign th { padding: 30px 0 0 0; text-align: left; font-weight: normal; }
        .sign td { padding: 30px 0 0 0; border-bottom: 1px solid #000; text-align: right; font-size: 11px; }
    </style>
</head>
<body>

    {{-- Предупреждение сверху --}}
    <p class="header">
        Внимание! Оплата данного счета означает согласие с условиями поставки товара.
        Уведомление об оплате обязательно, в противном случае не гарантируется наличие
        товара на складе. Товар отпускается по факту прихода денег на р/с Поставщика,
        самовывозом, при наличии доверенности и паспорта.
    </p>

    {{-- Банковские реквизиты (как в статье) --}}
    <table class="details">
        <tbody>
            <tr>
                <td colspan="2" style="border-bottom: none;">{{ $payload->bankName ?? 'ПАО СБЕРБАНК' }}</td>
                <td width="10%">БИК</td>
                <td width="25%" style="border-bottom: none;">{{ $payload->seller->bik ?? '' }}</td>
            </tr>
            <tr>
                <td colspan="2" style="border-top: none; font-size: 9px;">Банк получателя</td>
                <td>Сч. №</td>
                <td style="border-top: none;">{{ $payload->seller->correspondent_account ?? '' }}</td>
            </tr>
            <tr>
                <td width="25%">ИНН {{ $payload->seller->inn }}</td>
                <td width="30%">КПП {{ $payload->seller->kpp ?? '—' }}</td>
                <td rowspan="3">Сч. №</td>
                <td rowspan="3">{{ $payload->seller->settlement_account ?? '' }}</td>
            </tr>
            <tr>
                <td colspan="2" style="border-bottom: none;"><b>{{ $payload->seller->name }}</b></td>
            </tr>
            <tr>
                <td colspan="2" style="border-top: none; font-size: 9px;">Получатель</td>
            </tr>
        </tbody>
    </table>

    {{-- Заголовок --}}
    <h1>Счет на оплату № {{ $doc->number }} от {{ $doc->document_date->format('d.m.Y') }} г.</h1>

    {{-- Поставщик и Покупатель --}}
    <table class="contract">
        <tbody>
            <tr>
                <td>Поставщик:</td>
                <th>{{ $payload->seller->displayName }}</th>
            </tr>
            <tr>
                <td>Покупатель:</td>
                <th>{{ $payload->buyer->displayName }}</th>
            </tr>
            <tr>
                <td>Основание:</td>
                <th>{{ $payload->basis }}</th>
            </tr>
        </tbody>
    </table>

    {{-- Таблица позиций --}}
    <table class="list">
        <thead>
            <tr>
                <th width="5%">№</th>
                <th width="50%">Наименование товара, работ, услуг</th>
                <th width="8%">Коли-<br>чество</th>
                <th width="5%">Ед.<br>изм.</th>
                <th width="14%">Цена</th>
                <th width="18%">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payload->rows as $index => $row)
                <tr>
                    <td align="center">{{ $index + 1 }}</td>
                    <td align="left">{{ $row->name }}</td>
                    <td align="center">{{ $row->quantityFormatted }}</td>
                    <td align="center">{{ $row->unit }}</td>
                    <td align="right">{{ $row->priceFormatted }}</td>
                    <td align="right">{{ $row->amountFormatted }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5">Итого:</th>
                <th>{{ $payload->totalSumFormatted }}</th>
            </tr>
            <tr>
                <th colspan="5">В том числе НДС:</th>
                <th>{{ $payload->vatInfo }}</th>
            </tr>
            <tr>
                <th colspan="5">Всего к оплате:</th>
                <th>{{ $payload->totalSumFormatted }}</th>
            </tr>
        </tfoot>
    </table>

    {{-- Сумма прописью --}}
    <div class="total">
        <p>Всего наименований {{ count($payload->rows) }}, на сумму {{ $payload->totalSumFormatted }} руб.</p>
        <p><strong>{{ $payload->totalSumInWords }}</strong></p>
    </div>

    {{-- Подписи --}}
    <div class="sign">
        <table>
            <tbody>
                <tr>
                    <th width="30%">Руководитель</th>
                    <td width="70%">{{ $payload->director }}</td>
                </tr>
                <tr>
                    <th>Бухгалтер</th>
                    <td>{{ $payload->accountant }}</td>
                </tr>
            </tbody>
        </table>
    </div>

</body>
</html>