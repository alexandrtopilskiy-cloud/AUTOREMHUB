{{-- Бумажная копия документа ЭДО без документа заявки: только сведения из ЭДО. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ $edo['name'] }}</title>
    <style>
        @page { margin: 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 6px; }
        .sub { text-align: center; color: #444; margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>{{ $edo['name'] }}</h1>
    <div class="sub">№ {{ $edo['number'] ?: '—' }} от {{ $edo['date'] ?: '—' }}</div>
    @include('pdf.closing._edo_stamp')
</body>
</html>
