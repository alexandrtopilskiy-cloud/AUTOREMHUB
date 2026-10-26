<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; line-height: 1.5; }
        .title { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="title">ОТЧЕТ АГЕНТА № {{ $request->number }}</div>
    <p style="text-align: right;">{{ $date }}</p>

    <p>Настоящим УК <strong>{{ $uk->name }}</strong> (Агент) отчитывается перед Заказчиком <strong>{{ $customer->name }}</strong> (Принципал) о выполнении поручения по организации ремонта ТС.</p>

    <p><strong>Результат выполнения:</strong></p>
    <ul>
        <li>Заявка на ремонт № {{ $request->number }}</li>
        <li>Заказ-наряд № {{ $workOrder->number }}</li>
        <li>Итоговая стоимость работ и запчастей: <strong>{{ number_format($workOrder->total, 2, '.', ' ') }} руб.</strong></li>
    </ul>

    <p>Претензий по объему и качеству выполненных работ Принципал не имеет.</p>

    <br><br>
    <table style="width: 100%;">
        <tr>
            <td width="50%">Агент (УК): _________________ / {{ $uk->director_name }}</td>
            <td width="50%">Принципал (Заказчик): _________________ / {{ $customer->director_name }}</td>
        </tr>
    </table>
</body>
</html>
