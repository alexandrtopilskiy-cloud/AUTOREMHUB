<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Счёт на оплату № {{ $invoice->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .title { font-size: 18px; font-weight: bold; margin-bottom: 20px; text-align: center; }
        .requisites { width: 100%; margin-bottom: 20px; }
        .requisites td { padding: 5px; vertical-align: top; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .table th, .table td { border: 1px solid #000; padding: 8px; text-align: left; }
        .table th { background-color: #f0f0f0; }
        .text-right { text-align: right; }
        .total { font-size: 14px; font-weight: bold; }
        .footer { margin-top: 30px; font-size: 10px; color: #666; }
    </style>
</head>
<body>

    <div class="header">
        <div>
            <strong>{{ $uk->name ?? 'Управляющая компания' }}</strong><br>
            ИНН: {{ $uk->inn ?? '' }}<br>
            КПП: {{ $uk->kpp ?? '' }}<br>
            Адрес: {{ $uk->legal_address ?? '' }}
        </div>
        <div style="text-align: right;">
            <strong>Счёт на оплату № {{ $invoice->number }}</strong><br>
            от {{ $date }}<br>
        </div>
    </div>

    <div class="title">Счёт на оплату</div>

    <table class="requisites">
        <tr>
            <td width="15%"><strong>Поставщик:</strong></td>
            <td>{{ $uk->name ?? 'Управляющая компания' }}</td>
        </tr>
        <tr>
            <td><strong>Покупатель:</strong></td>
            <td>{{ $customer->name ?? 'Заказчик' }} (ИНН: {{ $customer->inn ?? '' }})</td>
        </tr>
        <tr>
            <td><strong>Основание:</strong></td>
            <td>Заявка на ремонт № {{ $request->number }}</td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>№</th>
                <th>Наименование работ, услуг</th>
                <th>Кол-во</th>
                <th>Ед.</th>
                <th>Цена, руб.</th>
                <th>Сумма, руб.</th>
            </tr>
        </thead>
        <tbody>
            @if($workOrder && $workOrder->items->count() > 0)
                @foreach($workOrder->items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $item->name }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>шт.</td>
                        <td class="text-right">{{ number_format($item->price, 2, '.', ' ') }}</td>
                        <td class="text-right">{{ number_format($item->price * $item->quantity, 2, '.', ' ') }}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td>1</td>
                    <td>Агентское вознаграждение по заявке {{ $request->number }}</td>
                    <td>1</td>
                    <td>усл.</td>
                    <td class="text-right">{{ number_format($invoice->amount, 2, '.', ' ') }}</td>
                    <td class="text-right">{{ number_format($invoice->amount, 2, '.', ' ') }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table width="100%">
        <tr>
            <td width="70%"></td>
            <td width="30%">
                <table class="table total">
                    <tr>
                        <td>Итого:</td>
                        <td class="text-right">{{ number_format($invoice->amount, 2, '.', ' ') }} руб.</td>
                    </tr>
                    <tr>
                        <td>Без НДС:</td>
                        <td class="text-right">0.00 руб.</td>
                    </tr>
                    <tr>
                        <td>Всего к оплате:</td>
                        <td class="text-right">{{ number_format($invoice->amount, 2, '.', ' ') }} руб.</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="footer">
        Счёт сформирован автоматически в системе AUTOREMHUB.
    </div>

</body>
</html>
