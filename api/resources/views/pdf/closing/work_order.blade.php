@extends('pdf.closing._layout')
@section('content')
    @include('pdf.closing._parties')

    @if(!empty($payload['completed_at_text']))
        <p class="muted">Работы завершены: {{ $payload['completed_at_text'] }}</p>
    @endif

    @include('pdf.closing._rows')

    @if(!empty($payload['discount_text']))
        <p class="muted">{{ $payload['discount_text'] }}</p>
    @endif

    <table class="sign"><tr>
        <td>Исполнитель: ____________ / {{ $payload['seller']['director'] ?? '' }}</td>
        <td>Заказчик: ____________ / {{ $payload['buyer']['director'] ?? '' }}</td>
    </tr></table>
@endsection