<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><style>body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; line-height: 1.5; } .title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 20px; }</style></head>
<body>
    <div class="title">АГЕНТСКИЙ ДОГОВОР № {{ $customer->id }}</div>
    <p style="text-align: right;">{{ $date }}</p>
    <p><strong>{{ $uk->name }}</strong> (Агент) и <strong>{{ $customer->name }}</strong> (Принципал) заключили настоящий договор о нижеследующем...</p>
    <p>Агент обязуется за вознаграждение совершать по поручению Принципала юридические и иные действия...</p>
    <br><br>
    <p>Агент: _________________ / {{ $uk->director_name }}</p>
    <p>Принципал: _________________ / {{ $customer->director_name }}</p>
</body>
</html>
