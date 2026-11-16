{{--
    Отметка об электронном документообороте на бумажной копии документа ЭДО.
    $edo: см. App\Services\Edo\EdoPaperCopyService::stamp().
--}}
@if (!empty($edo))
<div style="page-break-inside: avoid; margin-top: 14px; border: 1.5px solid #1f4e9c; border-radius: 4px; padding: 6px 8px; font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #10264d;">
    <div style="font-weight: bold; font-size: 9px; margin-bottom: 3px;">
        Документ передан через оператора ЭДО «{{ $edo['provider'] }}» · бумажная копия электронного документа
    </div>
    <table style="width: 100%; border-collapse: collapse; margin: 0;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding: 1px 4px 1px 0;">
                Документ: <b>{{ $edo['name'] }}</b><br>
                Номер / дата в ЭДО: {{ $edo['number'] ?: '—' }} от {{ $edo['date'] ?: '—' }}@if (!empty($edo['sum'])) · сумма {{ $edo['sum'] }} ₽@endif<br>
                Отправитель: {{ $edo['sender'] }}<br>
                Получатель: {{ $edo['receiver'] }}
            </td>
            <td style="width: 50%; vertical-align: top; padding: 1px 0 1px 4px;">
                Идентификатор: {{ $edo['externalId'] }}<br>
                Статус: <b>{{ $edo['state'] }}</b><br>
                Сведения получены: {{ $edo['readAt'] }}@if (!empty($edo['source'])) ({{ $edo['source'] }})@endif
            </td>
        </tr>
    </table>
    @if (!empty($edo['signatures']))
        <div style="font-weight: bold; margin-top: 4px;">Электронные подписи</div>
        <table style="width: 100%; border-collapse: collapse; margin: 0;">
            @foreach ($edo['signatures'] as $signature)
                <tr>
                    <td style="border-top: 1px solid #b9c7e0; padding: 1px 4px 1px 0;">{{ $signature['owner'] }}@if (!empty($signature['position'])), {{ $signature['position'] }}@endif</td>
                    <td style="border-top: 1px solid #b9c7e0; padding: 1px 4px;">{{ $signature['organization'] }}@if (!empty($signature['inn'])) · ИНН {{ $signature['inn'] }}@endif</td>
                    <td style="border-top: 1px solid #b9c7e0; padding: 1px 0 1px 4px;">{{ $signature['file'] }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <div style="margin-top: 4px;">Электронные подписи: документ ещё не подписан.</div>
    @endif
    @if (!empty($edo['events']))
        <div style="font-weight: bold; margin-top: 4px;">События документооборота</div>
        <table style="width: 100%; border-collapse: collapse; margin: 0;">
            @foreach ($edo['events'] as $event)
                <tr>
                    <td style="width: 28%; border-top: 1px solid #b9c7e0; padding: 1px 4px 1px 0;">{{ $event['at'] }}</td>
                    <td style="border-top: 1px solid #b9c7e0; padding: 1px 0 1px 4px;">{{ $event['name'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    @if (!empty($edo['notice']))
        <div style="margin-top: 4px; color: #8a4b00;">{{ $edo['notice'] }}</div>
    @endif
</div>
@endif
