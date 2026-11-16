<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} № {{ $doc->number }}</title>
    <style>
        @page { margin: 10mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #000; line-height: 1.3; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .header-table td { vertical-align: top; }
        .header-logo { font-size: 40px; font-weight: bold; letter-spacing: -2px; }
        .header-req { font-size: 8px; text-align: center; } /* Изменили на center для шапки */
        .title { font-size: 16px; font-weight: bold; margin: 10px 0 5px; }
        .info-table td { padding: 2px 4px; vertical-align: top; }
        .info-label { color: #555; width: 100px; }
        .grid-table th, .grid-table td { border: 1px solid #000; padding: 3px 4px; text-align: center; }
        .grid-table th { background-color: #f0f0f0; font-weight: bold; font-size: 8px; }
        .grid-table td.left { text-align: left; }
        .grid-table td.right { text-align: right; }
        .section-title { font-weight: bold; text-align: center; margin: 10px 0 3px; font-size: 10px; }
        .total-row td { font-weight: bold; }
        .signatures { margin-top: 30px; }
        .signatures td { width: 50%; vertical-align: bottom; padding-top: 20px; }
        .line { border-bottom: 1px solid #000; display: inline-block; width: 150px; }
        .small { font-size: 7px; color: #555; margin-top: 15px; }
        .empty-row td { text-align: center; color: #777; }
    </style>
</head>
<body>

    {{-- Шапка с реквизитами --}}
    <table class="header-table" style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
        <tr>
            {{-- Логотип --}}
            <td width="25%" style="vertical-align: top;">
                <div style="font-size: 14px; font-weight: bold; line-height: 1.1; word-wrap: break-word; color: #000;">
                    <span style="font-size: 11px; font-weight: normal;">
                        {{ $sender->short_name ?: $sender->name }}
                    </span>
                </div>
            </td>

            {{-- Реквизиты --}}
            <td width="50%" style="text-align: center; font-size: 9px; line-height: 1.3; vertical-align: top;">
                <strong>{{ $sender->name }}</strong><br>
                ИНН {{ $sender->inn }}<br>
                {{ $sender->legal_address }}<br>
                @if($sender->phone) тел.: {{ $sender->phone }}<br> @endif
                @if($sender->email) {{ $sender->email }} @endif
            </td>

            {{-- Пустая колонка для баланса --}}
            <td width="25%" style="vertical-align: top;"></td>
        </tr>
    </table>

    {{-- Заголовок и дата --}}
    <table>
        <tr>
            <td>
                <div class="title">{{ $title }} № {{ $doc->number }}</div>
                <div style="font-size: 8px;">от {{ $doc->document_date->format('d.m.Y') }} · по заявке на ремонт {{ $request->number }}</div>
            </td>
            <td style="text-align: right; font-size: 8px;">
                Принят: {{ $request->created_at?->format('d.m.Y H:i') ?? '—' }}<br>
                Закрыт: {{ $request->repair_completed_at?->format('d.m.Y H:i') ?? '—' }}
            </td>
        </tr>
    </table>

    {{-- Инфо о заказчике и авто --}}
    <table class="info-table">
        <tr>
            <td class="info-label">Заказчик:</td>
            <td><b>{{ $receiver->name }}</b>, ИНН {{ $receiver->inn }}, КПП {{ $receiver->kpp }}</td>
        </tr>
        <tr>
            <td class="info-label">Адрес, тел.:</td>
            <td>{{ $receiver->legal_address }}, тел.: {{ $receiver->phone }}</td>
        </tr>
        <tr>
            <td class="info-label">Модель:</td>
            <td><b>{{ trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: '—' }}</b></td>
            <td class="info-label">Год выпуска:</td>
            <td>{{ $vehicle->year ?? '—' }}</td>
        </tr>
        <tr>
            <td class="info-label">VIN-номер:</td>
            <td>{{ $vehicle->vin ?? '—' }}</td>
            <td class="info-label">Цвет:</td>
            <td>{{ $vehicle->color ?? '—' }}</td>
        </tr>
        <tr>
            <td class="info-label">Гос.номер:</td>
            <td><b>{{ $vehicle->plate ?? '—' }}</b></td>
            <td class="info-label">Пробег:</td>
            <td>{{ $mileage !== null && $mileage !== '' ? number_format((float) $mileage, 0, ',', ' ') . ' км' : '—' }}</td>
        </tr>
        <tr>
            <td class="info-label">Причина обращения:</td>
            <td colspan="3">{{ $reason ?: '—' }}</td>
        </tr>
        <tr>
            <td class="info-label">Рекомендации:</td>
            <td colspan="3">
                {{ $recommendations ?: '—' }}
            </td>
        </tr>
    </table>

    {{-- Таблица работ --}}
    @if(count($works) > 0)
        <div class="section-title">Выполненные работы</div>
        <table class="grid-table">
            <thead>
                <tr>
                    <th width="5%">№</th>
                    <th>Наименование</th>
                    <th width="10%">Крат.</th>
                    <th width="12%">Цена</th>
                    <th width="12%">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach($works as $index => $work)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="left">{{ $work['name'] }}</td>
                        <td>{{ rtrim(rtrim(number_format($work['quantity'], 3, '.', ''), '0'), '.') }}</td>
                        <td class="right">{{ number_format($work['price'], 2, '.', ' ') }}</td>
                        <td class="right">{{ number_format($work['amount'], 2, '.', ' ') }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="4" class="right">Итого:</td>
                    <td class="right">{{ number_format($totalWorks, 2, '.', ' ') }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- Таблица запчастей --}}
    @if(count($parts) > 0)
        <div class="section-title">Запасные части и материалы</div>
        <table class="grid-table">
            <thead>
                <tr>
                    <th width="5%">№</th>
                    <th width="10%">Номер</th>
                    <th>Наименование</th>
                    <th width="8%">Кол-во</th>
                    <th width="12%">Цена</th>
                    <th width="12%">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach($parts as $index => $part)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $part['sku'] ?? '—' }}</td>
                        <td class="left">{{ $part['name'] }}</td>
                        <td>{{ rtrim(rtrim(number_format($part['quantity'], 3, '.', ''), '0'), '.') }} {{ $part['unit'] ?? 'шт' }}</td>
                        <td class="right">{{ number_format($part['price'], 2, '.', ' ') }}</td>
                        <td class="right">{{ number_format($part['amount'], 2, '.', ' ') }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="5" class="right">Итого:</td>
                    <td class="right">{{ number_format($totalParts, 2, '.', ' ') }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- Итоги --}}
    <table style="font-weight: bold; font-size: 11px; margin-top: 10px;">
        <tr>
            <td>Сумма документа:</td>
            <td class="right">{{ number_format($totalSum, 2, '.', ' ') }} ({{ $vatText }})</td>
        </tr>
        <tr>
            <td>Задолженность:</td>
            <td class="right">{{ number_format($totalSum, 2, '.', ' ') }}</td>
        </tr>
    </table>

    @if(!empty($agentDiscount) && (float) ($agentDiscount['amount'] ?? 0) > 0)
        <p style="font-size: 8px;">Стоимость указана с учётом агентской скидки СТО
            {{ ($agentDiscount['percent'] ?? null) !== null ? rtrim(rtrim(number_format((float) $agentDiscount['percent'], 2, ',', ''), '0'), ',') . '%' : '' }}
            ({{ number_format((float) $agentDiscount['amount'], 2, ',', ' ') }} ₽ от {{ number_format((float) $agentDiscount['fullAmount'], 2, ',', ' ') }} ₽).</p>
    @endif

    {{-- Текст и подписи --}}
    <div class="small">
        Исполнитель несет гарантийные обязательства при условии соблюдения потребителем правил эксплуатации и рекомендаций исполнителя по использованию результатов работ (услуг).<br>
        Я (Заказчик) предоставляю согласие на обработку моих персональных данных... Предоставляю / Не предоставляю. (нужное подчеркнуть)<br>
        Претензий по комплектности не имею. С рекомендациями по использованию результатов работ ознакомлен.
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div>Заказчик: <span class="line"></span></div>
                <div style="margin-top: 5px;">{{ $receiver->director_name }}</div>
            </td>
            <td>
                <div>Мастер-приемщик: <span class="line"></span></div>
                <div style="margin-top: 5px;">{{ $sender->director_name }}</div>
            </td>
        </tr>
    </table>

</body>
</html>