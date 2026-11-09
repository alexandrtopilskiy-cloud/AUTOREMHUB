@extends('pdf.closing._layout')

@section('content')
    <h1>Универсальный передаточный документ № {{ $number }} от {{ $date }}</h1>

    <div class="parties">
        <p><strong>Продавец:</strong> {{ $seller->name }} (ИНН: {{ $seller->inn }})</p>
        <p><strong>Покупатель:</strong> {{ $buyer->name }} (ИНН: {{ $buyer->inn }})</p>
    </div>

    <table class="rows">
        <thead>
            <tr>
                <th>№</th>
                <th>Наименование</th>
                <th>Кол-во</th>
                <th>Цена</th>
                <th>Сумма</th>
                <th>НДС</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $index => $row)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $row->name }}</td>
                    <td>{{ $row->amount }}</td>
                    <td>{{ $row->price }}</td>
                    <td>{{ $row->totalSum }}</td>
                    <td>{{ $row->vatSum }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p><strong>Итого:</strong> {{ $totalSum }} ₽ (в т.ч. НДС: {{ $vatSum }} ₽)</p>
    <p><strong>Основание:</strong> {{ $basis }}</p>
@endsection
