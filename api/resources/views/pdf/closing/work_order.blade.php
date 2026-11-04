@extends('pdf.closing._layout')
@section('content')
    @include('pdf.closing._parties')
    @if(!empty($payload['request']['repairCompletedAt']))
        <p class="muted">Работы завершены: {{ \Carbon\CarbonImmutable::parse($payload['request']['repairCompletedAt'])->setTimezone(config('sto.service_timezone'))->format('d.m.Y H:i') }}</p>
    @endif
    @include('pdf.closing._rows')
    @if($side === 'station' && !empty($payload['agentDiscount']))
        <p class="muted">Стоимость указана с учётом агентской скидки СТО
            {{ $payload['agentDiscount']['percent'] !== null ? rtrim(rtrim(number_format((float) $payload['agentDiscount']['percent'], 2, ',', ''), '0'), ',') . '%' : '' }}
            ({{ number_format((float) $payload['agentDiscount']['amount'], 2, ',', ' ') }} ₽ от {{ number_format((float) $payload['agentDiscount']['fullAmount'], 2, ',', ' ') }} ₽).</p>
    @endif
    <table class="sign"><tr>
        <td>Исполнитель: ____________ / {{ $payload['seller']['director'] ?? '' }}</td>
        <td>Заказчик: ____________ / {{ $payload['buyer']['director'] ?? '' }}</td>
    </tr></table>
@endsection
