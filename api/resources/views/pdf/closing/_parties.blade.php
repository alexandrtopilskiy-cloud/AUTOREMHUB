<table class="parties">
    <tr>
        <td>
            <span class="label">{{ $sellerLabel ?? 'Исполнитель' }}:</span><br>
            {{ $payload['seller']['display_name'] ?? '' }}
        </td>
        <td>
            <span class="label">{{ $buyerLabel ?? 'Заказчик' }}:</span><br>
            {{ $payload['buyer']['display_name'] ?? '' }}
        </td>
    </tr>
</table>
@if(!empty($payload['vehicle_info']))
    <div class="box">
        {{ $payload['vehicle_info'] }}
    </div>
@endif