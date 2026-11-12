<table class="rows">
    <thead>
        <tr>
            <th>№</th><th>Наименование работ, запчастей</th><th>Кол-во</th><th>Ед.</th>
            <th>Цена, ₽</th><th>НДС</th><th>Сумма, ₽</th>
        </tr>
    </thead>
    <tbody>
        @foreach($payload['rows'] as $index => $row)
            <tr>
                <td class="num">{{ $index + 1 }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['quantity_formatted'] }}</td>
                <td>{{ $row['unit'] }}</td>
                <td class="num">{{ $row['price_formatted'] }}</td>
                <td class="num">{{ $row['vat_label'] }}</td>
                <td class="num">{{ $row['amount_formatted'] }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total">
            <td colspan="6" class="num">Итого:</td>
            <td class="num">{{ $payload['totals']['amount_formatted'] }}</td>
        </tr>
        <tr>
            <td colspan="6" class="num">В т. ч. НДС:</td>
            <td class="num">{{ $payload['totals']['vat_formatted'] }}</td>
        </tr>
    </tfoot>
</table>
