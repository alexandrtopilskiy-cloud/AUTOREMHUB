@extends('pdf.closing._layout')
@section('content')
    <p class="muted">Статус 1 — счёт-фактура и передаточный документ (акт). Основание передачи: {{ $payload['basis'] ?? '' }}.</p>
    @include('pdf.closing._parties', ['sellerLabel' => 'Продавец (исполнитель)', 'buyerLabel' => 'Покупатель (заказчик)'])
    @include('pdf.closing._rows')
    <table class="sign"><tr>
        <td>Работы (услуги) сдал, товар передал:<br><br>____________ / {{ $payload['seller']['director'] ?? '' }}</td>
        <td>Работы (услуги) принял, товар получил:<br><br>____________ / {{ $payload['buyer']['director'] ?? '' }}</td>
    </tr></table>
@endsection
