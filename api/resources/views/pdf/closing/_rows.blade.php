@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
@endphp
<table class="rows">
    <thead>
        <tr>
            <th>№</th><th>Наименование работ, запчастей</th><th>Кол-во</th><th>Ед.</th>
            <th>Цена, ₽</th><th>НДС</th><th>Сумма, ₽</th>
        </tr>
    </thead>
    <tbody>
        @foreach($payload['rows'] ?? [] as $i => $row)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $row['quantity'], 3, ',', ' '), '0'), ',') }}</td>
                <td>{{ $row['unit'] }}</td>
                <td class="num">{{ $money($row['price']) }}</td>
                <td class="num">{{ $row['vatRate'] !== null && $row['vatRate'] > 0 ? rtrim(rtrim(number_format((float) $row['vatRate'], 2, ',', ''), '0'), ',') . '%' : 'без НДС' }}</td>
                <td class="num">{{ $money($row['amount']) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total"><td colspan="6" class="num">Итого:</td><td class="num">{{ $money($payload['totals']['amount'] ?? 0) }}</td></tr>
        <tr><td colspan="6" class="num">В т. ч. НДС:</td><td class="num">{{ (float) ($payload['totals']['vat'] ?? 0) > 0 ? $money($payload['totals']['vat']) : 'без НДС' }}</td></tr>
    </tfoot>
</table>
