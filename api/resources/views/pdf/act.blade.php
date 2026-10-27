<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
        .header { text-align: center; margin-bottom: 20px; }
        .title { font-size: 16px; font-weight: bold; }
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { border: 1px solid #000; padding: 5px; }
        .table th { background-color: #f0f0f0; }
        .text-right { text-align: right; }
        .signatures { margin-top: 40px; width: 100%; }
        .signatures td { width: 50%; vertical-align: top; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Акт выполненных работ № {{ $workOrder->number }}</div>
        <div>к Заявке № {{ $request->number }} от {{ $date }}</div>
    </div>

    <table style="width: 100%; margin-bottom: 15px;">
        <tr>
            <td width="50%">
                <strong>Исполнитель:</strong><br>
                {{ $station->name ?? '' }}<br>
                ИНН: {{ $station->inn ?? '' }}<br>
                КПП: {{ $station->kpp ?? '' }}
            </td>
            <td width="50%">
                <strong>Заказчик:</strong><br>
                {{ $uk->name ?? '' }}<br>
                ИНН: {{ $uk->inn ?? '' }}<br>
                КПП: {{ $uk->kpp ?? '' }}
            </td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>№</th>
                <th>Наименование работ, услуг</th>
                <th>Кол-во</th>
                <th>Цена, руб.</th>
                <th>Сумма, руб.</th>
            </tr>
        </thead>
        <tbody>
            @foreach($workOrder->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->name }}</td>
                <td>{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->price, 2, '.', ' ') }}</td>
                <td class="text-right">{{ number_format($item->price * $item->quantity, 2, '.', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div style="text-align: right; margin-top: 10px; font-weight: bold;">
        Итого: {{ number_format($workOrder->total, 2, '.', ' ') }} руб.
    </div>

    <table class="signatures">
        <tr>
            <td>
                <strong>Исполнитель (СТО):</strong><br><br>
                _________________ / {{ $station->director_name ?? 'Подпись' }}
            </td>
            <td>
                <strong>Заказчик (УК):</strong><br><br>
                _________________ / {{ $uk->director_name ?? 'Подпись' }}
            </td>
        </tr>
    </table>
</body>
</html>
