<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; }
        .title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 20px; }
        .box { border: 1px solid #000; padding: 15px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="title">Чек об оплате счёта № {{ $invoice->number }}</div>
    <p style="text-align: right;">{{ $date }}</p>

    <div class="box">
        <strong>Поставщик:</strong> {{ $uk->name ?? '' }}<br>
        <strong>Покупатель:</strong> {{ $customer->name ?? '' }}<br>
        <strong>Сумма оплаты:</strong> {{ number_format($invoice->amount, 2, '.', ' ') }} руб.<br>
    </div>

    <p>Настоящий документ подтверждает факт оплаты счёта по заявке № {{ $request->number }}.</p>

    <br><br>
    <p>Подпись: _________________</p>
</body>
</html>