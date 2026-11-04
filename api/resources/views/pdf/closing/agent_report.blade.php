@extends('pdf.closing._layout')
@section('content')
    @include('pdf.closing._parties', ['sellerLabel' => 'Агент', 'buyerLabel' => 'Принципал'])
    <p>По агентскому договору № {{ $payload['contract'] ?? '' }} Агент сообщает, что по поручению Принципала организовал
        ремонт транспортного средства по заявке {{ $payload['request']['number'] ?? '' }} на станции технического обслуживания
        «{{ $payload['station']['name'] ?? '' }}»{{ !empty($payload['station']['inn']) ? ' (ИНН ' . $payload['station']['inn'] . ')' : '' }}.
        Основание: {{ $payload['basis'] ?? '' }}.</p>
    @include('pdf.closing._rows')
    <p>Стоимость выполненных работ и запчастей: <b>{{ number_format((float) ($payload['totals']['amount'] ?? 0), 2, ',', ' ') }} ₽</b>.
        Принципал претензий по объёму и качеству оказанных услуг не имеет.</p>
    <table class="sign"><tr>
        <td>Агент: ____________ / {{ $payload['seller']['director'] ?? '' }}</td>
        <td>Принципал: ____________ / {{ $payload['buyer']['director'] ?? '' }}</td>
    </tr></table>
@endsection
