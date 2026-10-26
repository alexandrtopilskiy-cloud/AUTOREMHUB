<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; line-height: 1.6; }
        .title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 30px; }
        .content { text-indent: 30px; text-align: justify; }
        .box { margin: 20px 0; padding: 15px; border: 1px solid #000; background: #fafafa; }
        .signature { margin-top: 50px; }
        .driver-info { margin: 15px 0; font-weight: bold; }
    </style>
</head>
<body>
    <div class="title">ДОВЕРЕННОСТЬ № {{ $request->number }}</div>
    <div style="text-align: right; margin-bottom: 20px;">г. Москва, {{ $date }}</div>

    <div class="content">
        Настоящей доверенностью <strong>{{ $customer->name ?? '' }}</strong> (ИНН {{ $customer->inn ?? '' }}), именуемое в дальнейшем «Доверитель», уполномочивает:
    </div>

    <div class="driver-info">
        Водителя: <strong>{{ $driver?->fullName() ?? 'Не указан' }}</strong><br>
        @if($driver)
            @if($driver->phone) Телефон: {{ $driver->phone }}<br> @endif
            {{-- Если в снапшоте есть паспорт, можно вывести: --}}
            {{-- Паспорт: {{ $driver->passport_series }} {{ $driver->passport_number }} --}}
        @endif
    </div>

    <div class="content">
        на право управления транспортным средством:
    </div>

    <div class="box">
        <strong>Марка/Модель:</strong> {{ $vehicle->make ?? '' }} {{ $vehicle->model ?? '' }}<br>
        <strong>VIN:</strong> {{ $vehicle->vin ?? '' }}<br>
        <strong>Гос. номер:</strong> {{ $vehicle->plate ?? '' }}<br>
    </div>

    <div class="content">
        с целью доставки данного транспортного средства к месту проведения ремонта (СТО) <strong>{{ $station->name ?? '' }}</strong> и передаче его сотрудникам СТО.
    </div>

    <div class="content">
        Доверенность выдана сроком на 30 (тридцать) дней, без права передоверия.
    </div>

    <div class="signature">
        <table style="width: 100%;">
            <tr>
                <td width="50%">
                    <strong>Подпись Доверителя:</strong><br><br>
                    _________________ / {{ $customer->director_name ?? '' }}
                </td>
                <td width="50%">
                    <strong>Подпись Водителя:</strong><br><br>
                    _________________ / {{ $driver?->fullName() ?? '' }}
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
