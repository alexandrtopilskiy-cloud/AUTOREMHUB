<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} № {{ $doc->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #111; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 4px; }
        .sub { text-align: center; color: #444; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        .parties td { vertical-align: top; padding: 4px 6px; border: 1px solid #999; width: 50%; }
        .rows th, .rows td { border: 1px solid #777; padding: 3px 4px; }
        .rows th { background: #eee; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #555; }
        .box { border: 1px solid #999; padding: 6px 8px; margin: 10px 0; }
        .sign td { padding-top: 26px; width: 50%; vertical-align: top; }
        .total td { font-weight: bold; }
        .label { font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $title }} № {{ $doc->number }}</h1>
    <div class="sub">от {{ $doc->document_date->format('d.m.Y') }} · по заявке на ремонт {{ $payload['request']['number'] ?? '' }}</div>
    @yield('content')
</body>
</html>
