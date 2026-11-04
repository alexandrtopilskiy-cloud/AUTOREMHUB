@extends('pdf.closing._layout')
@section('content')
    <table class="parties">
        <tr>
            <td><span class="label">Передал (СТО):</span><br>{{ $payload['seller']['name'] ?? '' }}{{ !empty($payload['seller']['inn']) ? ', ИНН ' . $payload['seller']['inn'] : '' }}</td>
            <td><span class="label">Принял (Заказчик):</span><br>{{ $payload['buyer']['name'] ?? '' }}{{ !empty($payload['buyer']['inn']) ? ', ИНН ' . $payload['buyer']['inn'] : '' }}</td>
        </tr>
    </table>
    <div class="box">
        <span class="label">Транспортное средство:</span> {{ $payload['vehicle']['name'] ?? '' }}{{ !empty($payload['vehicle']['year']) ? ', ' . $payload['vehicle']['year'] . ' г. в.' : '' }}<br>
        <span class="label">Госномер:</span> {{ $payload['vehicle']['plate'] ?: '—' }} · <span class="label">VIN:</span> {{ $payload['vehicle']['vin'] ?: '—' }}<br>
        <span class="label">Пробег при выдаче:</span> {{ $payload['request']['mileage'] !== '' && $payload['request']['mileage'] !== null ? number_format((float) $payload['request']['mileage'], 0, ',', ' ') . ' км' : '—' }}<br>
        @if(!empty($payload['workOrder']))<span class="label">Заказ-наряд СТО:</span> {{ $payload['workOrder'] }}<br>@endif
        @if(!empty($payload['acceptanceAct']))<span class="label">Акт приёма ТС на СТО:</span> {{ $payload['acceptanceAct'] }}@endif
    </div>
    <div class="box">
        <span class="label">Водитель Заказчика:</span> {{ $payload['driver']['fullName'] ?: '—' }}{{ !empty($payload['driver']['phone']) ? ', тел. ' . $payload['driver']['phone'] : '' }}<br>
        <span class="label">Действует по доверенности:</span> № {{ $payload['powerOfAttorney']['number'] ?? '—' }}{{ !empty($payload['powerOfAttorney']['date']) ? ' от ' . \Carbon\CarbonImmutable::parse($payload['powerOfAttorney']['date'])->format('d.m.Y') : '' }}<br>
        Документы водителя (паспорт, водительское удостоверение) сверены с доверенностью: ______
    </div>
    <p>СТО передаёт, а водитель Заказчика принимает транспортное средство после выполнения работ по заявке
        {{ $payload['request']['number'] ?? '' }}. Комплектность и внешнее состояние ТС проверены, претензий не имеется.</p>
    <table class="sign"><tr>
        <td>ТС передал (СТО):<br><br>____________ / ____________________</td>
        <td>ТС принял (водитель):<br><br>____________ / {{ $payload['driver']['fullName'] ?? '' }}</td>
    </tr></table>
@endsection
