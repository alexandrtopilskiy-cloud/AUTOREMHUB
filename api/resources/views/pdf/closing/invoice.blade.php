@extends('pdf.closing._layout')
@section('content')
    <div class="box">
        <span class="label">Получатель:</span> {{ $payload['seller']['name'] ?? '' }}, ИНН {{ $payload['seller']['inn'] ?? '' }}<br>
        <span class="label">Банк:</span> {{ $payload['seller']['bank'] ?: '—' }}, БИК {{ $payload['seller']['bik'] ?: '—' }}<br>
        <span class="label">Р/с:</span> {{ $payload['seller']['account'] ?: '—' }} · <span class="label">К/с:</span> {{ $payload['seller']['corrAccount'] ?: '—' }}
    </div>
    @include('pdf.closing._parties', ['sellerLabel' => 'Поставщик (исполнитель)', 'buyerLabel' => 'Покупатель (заказчик)'])
    <p>Основание: {{ $payload['basis'] ?? '' }}</p>
    @include('pdf.closing._rows')
    <p><b>Всего к оплате: {{ number_format((float) ($payload['totals']['amount'] ?? 0), 2, ',', ' ') }} ₽</b></p>
    <table class="sign"><tr>
        <td>Руководитель: ____________ / {{ $payload['seller']['director'] ?? '' }}</td>
        <td></td>
    </tr></table>
@endsection
