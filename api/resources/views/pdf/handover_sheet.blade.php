{{--
    Лист приёма-передачи ТС (вариант «Подробный»).
    $sheet['direction']: in  — приём ТС от Заказчика на СТО,
                         out — передача ТС от СТО Заказчику.
    Реквизиты сторон, водитель, ТС и представитель СТО заполняются из ЗР;
    пробег, топливо, отметки и подписи — от руки.
--}}
@php
    $in = ($sheet['direction'] ?? 'in') === 'in';
    $c = $sheet['customer'] ?? [];
    $s = $sheet['station'] ?? [];
    $drv = $sheet['driver'] ?? [];
    $rep = $sheet['stationRepresentative'] ?? [];
    $poa = $sheet['powerOfAttorney'] ?? [];
    $v = $sheet['vehicle'] ?? [];
    $fmt = fn ($d) => $d ? \Carbon\CarbonImmutable::parse($d)->format('d.m.Y') : '';
    $req = function (array $p): string {
        $parts = [];
        if (($p['inn'] ?? '') !== '') { $parts[] = 'ИНН ' . $p['inn']; }
        if (($p['kpp'] ?? '') !== '') { $parts[] = 'КПП ' . $p['kpp']; }
        return implode(', ', $parts);
    };
    $poaText = ($poa['number'] ?? '') !== '' ? 'по доверенности № ' . $poa['number'] . (($poa['date'] ?? '') ? ' от ' . $fmt($poa['date']) : '') : '';
    $scheme = 'data:image/png;base64,' . base64_encode((string) @file_get_contents(resource_path('images/handover-car-scheme.png')));
    $cb = '<span class="cb"></span>';
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Лист приёма-передачи ТС № {{ $sheet['number'] ?? '' }}</title>
    <style>
        @page { margin: 11mm 11mm 10mm 13mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6px; color: #111; margin: 0; }
        h1 { font-size: 12.5px; text-align: center; margin: 0 0 2px; }
        .sub { text-align: center; color: #444; margin: 0 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .g td, .g th { border: 1px solid #888; padding: 2px 4px; }
        .g th { background: #eef1f5; text-align: left; font-weight: bold; }
        .l { color: #555; font-size: 7.8px; }
        .f { border-bottom: 1px solid #333; display: inline-block; width: 70px; height: 9px; }
        .sec { font-weight: bold; font-size: 9.4px; margin: 6px 0 2px; color: #14325a; }
        .box { border: 1px solid #888; padding: 4px 6px; margin: 4px 0; }
        .cb { display: inline-block; width: 7px; height: 7px; border: 1px solid #333; margin-right: 3px; }
        .c { text-align: center; }
        .sign td { padding-top: 10px; width: 50%; }
        .small { font-size: 7.6px; color: #444; }
        .dmg td { height: 17px; padding: 0 4px; }
    </style>
</head>
<body>
    <h1>Лист приёма-передачи транспортного средства № {{ $sheet['number'] ?? '' }}</h1>
    <div class="sub">
        {{ $in ? 'Приём ТС от Заказчика на СТО' : 'Передача ТС от СТО Заказчику' }}
        · от {{ $fmt($sheet['date'] ?? null) ?: '____.____.20____' }}
        · по заявке на ремонт {{ $sheet['requestNumber'] ?? '' }}
    </div>

    @php
        $customerCell = '<b>' . e($c['name'] ?? '') . '</b><br><span class="l">' . e($req($c)) . (($c['address'] ?? '') !== '' ? '<br>' . e($c['address']) : '') . '</span>'
            . '<br>Представитель: <b>' . e(($drv['fullName'] ?? '') ?: '________________') . '</b>, водитель'
            . '<br><span class="l">' . e(trim((($drv['phone'] ?? '') !== '' ? 'тел. ' . $drv['phone'] : '') . ($poaText !== '' ? '; ' . $poaText : ''), '; ')) . '</span>';
        $stationCell = '<b>' . e($s['name'] ?? '') . '</b><br><span class="l">' . e($req($s)) . (($s['address'] ?? '') !== '' ? '<br>' . e($s['address']) : '') . '</span>'
            . '<br>Представитель: <b>' . e(($rep['fullName'] ?? '') ?: '________________') . '</b>'
            . (($rep['phone'] ?? '') !== '' ? '<br><span class="l">тел. ' . e($rep['phone']) . '</span>' : '');
    @endphp
    <table class="g">
        <tr>
            <th style="width:50%">{{ $in ? 'Передаёт (Заказчик)' : 'Передаёт (СТО)' }}</th>
            <th>{{ $in ? 'Принимает (СТО)' : 'Принимает (Заказчик)' }}</th>
        </tr>
        <tr>
            <td>{!! $in ? $customerCell : $stationCell !!}</td>
            <td>{!! $in ? $stationCell : $customerCell !!}</td>
        </tr>
    </table>

    <div class="sec">1. Транспортное средство</div>
    <table class="g">
        <tr><th>Марка, модель</th><th>Гос. номер</th><th>VIN</th><th>Год выпуска</th><th>Пробег, км</th><th>Топливо / заряд</th></tr>
        <tr>
            <td><b>{{ $v['name'] ?? '' }}</b></td>
            <td><b>{{ ($v['plate'] ?? '') ?: '—' }}</b></td>
            <td><b>{{ ($v['vin'] ?? '') ?: '—' }}</b></td>
            <td><b>{{ ($v['year'] ?? '') ?: '—' }}</b></td>
            <td><span class="f"></span></td>
            <td>{!! $cb !!}0 {!! $cb !!}¼ {!! $cb !!}½ {!! $cb !!}¾ {!! $cb !!}1</td>
        </tr>
    </table>
    <table class="g" style="margin-top:2px">
        <tr>
            <td style="width:50%">Состояние салона: {!! $cb !!}чистый {!! $cb !!}загрязнён {!! $cb !!}повреждения</td>
            <td>Состояние кузова: {!! $cb !!}чистый {!! $cb !!}загрязнён (осмотр ограничен)</td>
        </tr>
    </table>

    <div class="sec">2. Комплектность</div>
    <table class="g">
        @foreach ([
            ['Документы и ключи', 'СТС', 'Ключ зажигания, шт. ___', 'Брелок сигнализации / карта-ключ'],
            ['Оснащение', 'Запасное колесо / докатка', 'Домкрат, баллонный ключ', 'Буксировочный трос'],
            ['Безопасность', 'Аптечка', 'Огнетушитель', 'Знак аварийной остановки'],
            ['Салон', 'Коврики, шт. ___', 'Магнитола / мультимедиа', 'Видеорегистратор'],
            ['Внешние элементы', 'Колпаки колёс, шт. ___', 'Брызговики', ''],
        ] as $group)
            <tr>
                <th style="width:19%">{{ $group[0] }}</th>
                @foreach (array_slice($group, 1) as $item)
                    <td>@if ($item !== ''){!! $cb !!}{{ $item }}@endif</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <div class="sec">3. Сигнальные лампы на панели приборов при включении зажигания</div>
    <table class="g">
        <tr>
            <td>{!! $cb !!}Нет</td><td>{!! $cb !!}Check Engine</td><td>{!! $cb !!}ABS</td><td>{!! $cb !!}Подушки (SRS)</td><td>{!! $cb !!}Давление масла</td>
        </tr>
        <tr>
            <td>{!! $cb !!}АКБ / зарядка</td><td>{!! $cb !!}Давление шин</td><td>{!! $cb !!}ESP</td><td colspan="2">{!! $cb !!}Другое: ____________</td>
        </tr>
    </table>

    <div class="sec">4. Внешний осмотр: отметьте повреждения на схеме номером и опишите в таблице</div>
    <table>
        <tr>
            <td style="width:56%"><img src="{{ $scheme }}" style="width:100%"></td>
            <td style="padding-left:6px">
                <table class="g dmg">
                    <tr><th class="c" style="width:8%">№</th><th>Элемент</th><th style="width:13%">Код</th><th>Описание</th><th style="width:14%">Фото</th></tr>
                    @for ($i = 1; $i <= 10; $i++)
                        <tr><td class="c">{{ $i }}</td><td></td><td></td><td></td><td></td></tr>
                    @endfor
                </table>
                <div class="small" style="margin-top:3px; text-align:justify">
                    Обозначения: <b>Ц</b> — царапина, <b>В</b> — вмятина, <b>С</b> — скол, <b>Т</b> — трещина,
                    <b>К</b> — коррозия, <b>О</b> — отсутствует деталь, <b>Р</b> — ранее окрашено.
                </div>
            </td>
        </tr>
    </table>

    <div class="sec">5. Личные вещи в ТС / прочие замечания</div>
    <div class="box" style="height:22px"></div>
    <div class="small">
        Фотофиксация: ____ фото загружено в заявку {{ $sheet['requestNumber'] ?? '' }}.
        @if ($in)
            СТО не несёт ответственности за личные вещи, не указанные в листе. Скрытые дефекты, выявленные при диагностике, отражаются в заявке.
        @else
            Заказчик принял ТС после работ{{ !empty($sheet['workOrder']) ? ' по заказ-наряду ' . $sheet['workOrder'] : '' }}; состояние и комплектность сверены{{ !empty($sheet['acceptanceNumber']) ? ' с листом приёма ' . $sheet['acceptanceNumber'] : '' }}.
        @endif
    </div>


    @php
        $poaLine = ($poa['number'] ?? '') !== ''
            ? 'действует по доверенности № ' . $poa['number'] . ' от ' . (($poa['date'] ?? '') ? $fmt($poa['date']) : '____.____.20____')
            : 'действует по доверенности № ____________ от ____.____.20____';
        $customerSign = '____________ / ' . e(($drv['fullName'] ?? '') ?: '____________________') . '<br><span class="small">' . e($poaLine) . '</span>';
        $stationSign = '____________ / ' . e(($rep['fullName'] ?? '') ?: '____________________');
    @endphp
    <table class="sign">
        <tr>
            <td>
                {{ $in ? 'ТС сдал (представитель Заказчика)' : 'ТС выдал (представитель СТО)' }}:<br><br>
                {!! $in ? $customerSign : $stationSign !!}<br>
                <span class="small">дата, время ____.____.20____ ____:____</span>
            </td>
            <td>
                {{ $in ? 'ТС принял (представитель СТО)' : 'ТС получил (представитель Заказчика)' }}:<br><br>
                {!! $in ? $stationSign : $customerSign !!}<br>
                <span class="small">дата, время ____.____.20____ ____:____</span>
            </td>
        </tr>
    </table>
</body>
</html>
