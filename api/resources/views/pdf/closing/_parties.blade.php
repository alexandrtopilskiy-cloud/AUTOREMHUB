@php
    $party = function (array $p): string {
        return trim(($p['name'] ?? '') . (($p['inn'] ?? '') !== '' ? ', ИНН ' . $p['inn'] : '') . (($p['kpp'] ?? '') !== '' ? ', КПП ' . $p['kpp'] : '') . (($p['address'] ?? '') !== '' ? ', ' . $p['address'] : ''));
    };
@endphp
<table class="parties">
    <tr>
        <td><span class="label">{{ $sellerLabel ?? 'Исполнитель' }}:</span><br>{{ $party($payload['seller'] ?? []) }}</td>
        <td><span class="label">{{ $buyerLabel ?? 'Заказчик' }}:</span><br>{{ $party($payload['buyer'] ?? []) }}</td>
    </tr>
</table>
<div class="box">
    <span class="label">ТС:</span> {{ $payload['request']['vehicle'] ?? '' }}
    · Госномер {{ $payload['request']['plate'] ?? '—' }} · VIN {{ $payload['request']['vin'] ?? '—' }}
    @if(!empty($payload['request']['mileage'])) · Пробег {{ number_format((float) $payload['request']['mileage'], 0, ',', ' ') }} км @endif
</div>
