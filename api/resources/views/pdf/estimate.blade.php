<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { border: 1px solid #000; padding: 5px; }
        .table th { background-color: #f0f0f0; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <h2 style="text-align: center;">Предварительный заказ-наряд</h2>
    <p><strong>Заявка:</strong> {{ $request->number }} от {{ $date }}</p>
    <p><strong>Автомобиль:</strong> {{ $request->vehicleRecord->make }} {{ $request->vehicleRecord->model }} ({{ $request->vehicleRecord->vin }})</p>

    <table class="table">
        <thead>
            <tr>
                <th>№</th>
                <th>Работы и запчасти</th>
                <th>Кол-во</th>
                <th>Сумма, руб.</th>
            </tr>
        </thead>
        <tbody>
            @foreach($works as $index => $work)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $work->name }} (Работа)</td>
                <td>{{ $work->quantity }}</td>
                <td class="text-right">{{ number_format($work->line_amount, 2, '.', ' ') }}</td>
            </tr>
            @endforeach
            @foreach($parts as $index => $part)
            <tr>
                <td>{{ count($works) + $index + 1 }}</td>
                <td>{{ $part->name }} (Запчасть)</td>
                <td>{{ $part->quantity }}</td>
                <td class="text-right">{{ number_format($part->price * $part->quantity, 2, '.', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div style="text-align: right; margin-top: 15px; font-size: 14px; font-weight: bold;">
        Предварительная стоимость: {{ number_format($total, 2, '.', ' ') }} руб.
    </div>
</body>
</html>
