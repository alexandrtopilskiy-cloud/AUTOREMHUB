<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Акт приема-передачи</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; }
        .title { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 20px; }
        .box { border: 1px solid #000; padding: 10px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="title">Акт приема-передачи транспортного средства № {{ $act->number }}</div>
    <p style="text-align: right;">{{ $date }}</p>

    <div class="box">
        <strong>Автомобиль:</strong> {{ $vehicle->make ?? '' }} {{ $vehicle->model ?? '' }}<br>
        <strong>VIN:</strong> {{ $vehicle->vin ?? '' }}<br>
        <strong>Гос. номер:</strong> {{ $vehicle->plate ?? '' }}<br>
        <strong>Пробег:</strong> {{ $act->mileage ?? '0' }} км<br>
        <strong>Уровень топлива:</strong> {{ $act->fuel_level ?? '' }}
    </div>

    <!-- Остальной контент... -->
</body>
</html>