@extends('pdf.closing._layout')

@section('content')
    <h1>Счёт № {{ $number }} от {{ $date }}</h1>

    <div class="parties">
        <p><strong>От:</strong> {{ $sender->name }} (ИНН: {{ $sender->inn }})</p>
        <p><strong>Кому:</strong> {{ $receiver->name }} (ИНН: {{ $receiver->inn }})</p>
    </div>

    <p>
        {{ $isStationToManager
            ? 'Оплата услуг СТО по заявке ' . $requestNumber
            : 'Агентское вознаграждение по заявке ' . $requestNumber }}
    </p>

    <table class="total">
        <tr>
            <td><strong>Итого:</strong></td>
            <td>{{ $amount }} ₽</td>
        </tr>
        <tr>
            <td>В том числе НДС:</td>
            <td>{{ $vatAmount }} ₽</td>
        </tr>
    </table>
@endsection
