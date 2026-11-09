<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>УПД № {{ $document->number }} от {{ $document->date }}</title>
    <style>
        @page { margin: 10mm; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 9px;
            color: #000;
            margin: 0;
            padding: 0;
        }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .header-table td { padding: 1px 3px; }
        .bordered { border: 1px solid #000; }
        .bordered td, .bordered th { border: 1px solid #000; padding: 2px 3px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .small { font-size: 7.5px; }
        .tiny { font-size: 6.5px; }
        .no-border { border: none !important; }
        .nowrap { white-space: nowrap; }
        .underline { border-bottom: 1px solid #000; display: inline-block; }
        .signature-line { border-bottom: 1px solid #000; display: inline-block; min-width: 150px; }
        .items-table th { font-size: 7.5px; text-align: center; font-weight: normal; }
        .items-table td { font-size: 8px; }
        .mt-5 { margin-top: 5px; }
        .mt-10 { margin-top: 10px; }
    </style>
</head>
<body>

{{-- ===================== ЗАГОЛОВОК ===================== --}}
<table class="header-table">
    <tr>
        <td rowspan="2" style="width: 12%; font-size: 8px; line-height: 1.2;">
            Универсальный<br>передаточный<br>документ
        </td>
        <td style="width: 45%;">
            <span class="bold" style="font-size: 12px;">
                Счет-фактура № {{ $document->number }} от {{ $document->date }}
            </span>
        </td>
        <td style="width: 43%; text-align: right; font-size: 7.5px;">
            Приложение N 1 к постановлению Правительства Российской Федерации от 26.12.2011 №1137<br>
            (в ред. Постановления Правительства РФ от 23.01.2026 № 26)
        </td>
    </tr>
    <tr>
        <td colspan="2" style="font-size: 8px;">
            Исправление № -- от --
        </td>
    </tr>
</table>

{{-- Статус документа --}}
<table class="header-table mt-5" style="width: 100%;">
    <tr>
        <td style="width: 12%; font-size: 8px; vertical-align: top;">
            Статус: <span class="bordered" style="padding: 0 4px;">{{ $status }}</span>
        </td>
        <td style="font-size: 8px; line-height: 1.3;">
            <div>1 – счет-фактура и передаточный документ (акт)</div>
            <div>2 – передаточный документ (акт)</div>
        </td>
    </tr>
</table>

{{-- ===================== ПРОДАВЕЦ / ПОКУПАТЕЛЬ ===================== --}}
<table class="header-table mt-5" style="font-size: 8px;">
    <tr>
        <td style="width: 20%;">Продавец</td>
        <td class="bold" style="width: 55%;">{{ $sender['name'] }}</td>
        <td class="right tiny" style="width: 25%;">(2)</td>
    </tr>
    <tr>
        <td>Адрес</td>
        <td>{{ $sender['address'] }}</td>
        <td class="right tiny">(2а)</td>
    </tr>
    <tr>
        <td>ИНН/КПП продавца</td>
        <td>{{ $sender['inn'] }} / {{ $sender['kpp'] }}</td>
        <td class="right tiny">(2б)</td>
    </tr>
    <tr>
        <td>Грузоотправитель и его адрес:</td>
        <td class="bold">{{ $sender['name'] }}, {{ $sender['address'] }}</td>
        <td class="right tiny">(3)</td>
    </tr>
    <tr>
        <td>Грузополучатель и его адрес:</td>
        <td class="bold">{{ $receiver['name'] }}, {{ $receiver['address'] }}</td>
        <td class="right tiny">(4)</td>
    </tr>
    <tr>
        <td>К платежно-расчетному документу</td>
        <td> - </td>
        <td class="right tiny">(5)</td>
    </tr>
    <tr>
        <td>Документ об отгрузке:</td>
        <td>Передаточный документ, № {{ $document->number }} от {{ $document->date }}</td>
        <td class="right tiny">(5а)</td>
    </tr>
    <tr>
        <td>Покупатель</td>
        <td class="bold">{{ $receiver['name'] }}</td>
        <td class="right tiny">(6)</td>
    </tr>
    <tr>
        <td>Адрес</td>
        <td>{{ $receiver['address'] }}</td>
        <td class="right tiny">(6а)</td>
    </tr>
    <tr>
        <td>ИНН/КПП покупателя</td>
        <td>{{ $receiver['inn'] }} / {{ $receiver['kpp'] }}</td>
        <td class="right tiny">(6б)</td>
    </tr>
    <tr>
        <td>Валюта: наименование, код</td>
        <td>Российский рубль, код - 643</td>
        <td class="right tiny">(7)</td>
    </tr>
    <tr>
        <td>Идентификатор государственного контракта, договора (соглашения) (при наличии)</td>
        <td></td>
        <td class="right tiny">(8)</td>
    </tr>
</table>

{{-- ===================== ТАБЛИЦА ТОВАРОВ ===================== --}}
<table class="bordered items-table mt-10">
    <thead>
        <tr>
            <th rowspan="2" style="width: 5%;">Код товара/ работ, услуг</th>
            <th rowspan="2" style="width: 3%;">№ п/п</th>
            <th rowspan="2" style="width: 18%;">Наименование товара (описание выполненных работ, оказанных услуг), имущественного права</th>
            <th rowspan="2" style="width: 3%;">Код вида товара</th>
            <th colspan="2" style="width: 8%;">Единица измерения</th>
            <th rowspan="2" style="width: 6%;">Количество (объем)</th>
            <th rowspan="2" style="width: 7%;">Цена (тариф) за единицу измерения</th>
            <th rowspan="2" style="width: 8%;">Стоимость товаров (работ, услуг), имущественных прав без налога - всего</th>
            <th rowspan="2" style="width: 5%;">В том числе сумма акциза</th>
            <th rowspan="2" style="width: 5%;">Налоговая ставка</th>
            <th rowspan="2" style="width: 7%;">Сумма налога, предъявляемая покупателю</th>
            <th rowspan="2" style="width: 8%;">Стоимость товаров (работ, услуг), имущественных прав с налогом - всего</th>
            <th colspan="2" style="width: 7%;">Страна происхождения товара</th>
            <th rowspan="2" style="width: 5%;">Регистрационный номер декларации на товары или регистрационный номер партии товара, подлежащего прослеживаемости</th>
        </tr>
        <tr>
            <th class="tiny">код</th>
            <th class="tiny">условное обозначение (национальное)</th>
            <th class="tiny">Цифровой код</th>
            <th class="tiny">Краткое наименование</th>
        </tr>
        <tr class="bold tiny">
            <th>А</th>
            <th>1</th>
            <th>1а</th>
            <th>1б</th>
            <th>2</th>
            <th>2а</th>
            <th>3</th>
            <th>4</th>
            <th>5</th>
            <th>6</th>
            <th>7</th>
            <th>8</th>
            <th>9</th>
            <th>10</th>
            <th>10а</th>
            <th>11</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
        <tr>
            <td>{{ $item['code'] }}</td>
            <td class="center">{{ $loop->iteration }}</td>
            <td>{{ $item['name'] }}</td>
            <td>-</td>
            <td class="center">{{ $item['unit_code'] }}</td>
            <td class="center">{{ $item['unit_name'] }}</td>
            <td class="right">{{ $item['quantity'] }}</td>
            <td class="right">{{ $item['price'] }}</td>
            <td class="right">{{ $item['sum'] }}</td>
            <td class="center">без акциза</td>
            <td class="center">{{ $item['vat_rate'] }}</td>
            <td class="right">{{ $item['vat_sum'] }}</td>
            <td class="right">{{ $item['sum'] }}</td>
            <td class="center">-</td>
            <td class="center">-</td>
            <td class="center">-</td>
        </tr>
        @endforeach
        <tr class="bold">
            <td colspan="8" class="right">Всего к оплате</td>
            <td class="right">{{ $totalSum }}</td>
            <td class="center">X</td>
            <td class="center">Без НДС</td>
            <td class="right">{{ $totalSum }}</td>
            <td class="right">{{ $totalSum }}</td>
            <td colspan="3"></td>
        </tr>
    </tbody>
</table>

{{-- ===================== ПОДПИСИ РУКОВОДИТЕЛЯ ===================== --}}
<table class="mt-10" style="font-size: 8px; width: 100%;">
    <tr>
        <td style="width: 12%;">Документ составлен на</td>
        <td style="width: 5%;"><span class="underline"> </span> листах</td>
        <td colspan="4"></td>
    </tr>
</table>

<table class="mt-5" style="font-size: 8px; width: 100%;">
    <tr>
        <td style="width: 30%;">
            Руководитель организации<br>или иное уполномоченное лицо
        </td>
        <td style="width: 20%; text-align: center;">
            <div class="signature-line"></div>
            <div class="tiny">(подпись)</div>
        </td>
        <td style="width: 20%; text-align: center;">
            <div class="bold">{{ $sender['director_name'] }}</div>
            <div class="tiny">(ф.и.о.)</div>
        </td>
        <td style="width: 30%;">
            Главный бухгалтер<br>или иное уполномоченное лицо
        </td>
    </tr>
</table>

<table class="mt-5" style="font-size: 8px; width: 100%;">
    <tr>
        <td style="width: 30%;">
            Индивидуальный предприниматель<br>или иное уполномоченное лицо
        </td>
        <td style="width: 20%; text-align: center;">
            <div class="signature-line"></div>
            <div class="tiny">(подпись)</div>
        </td>
        <td style="width: 20%; text-align: center;">
            <div class="signature-line"></div>
            <div class="tiny">(ф.и.о.)</div>
        </td>
        <td style="width: 30%; text-align: center;">
            <div class="signature-line"></div>
            <div class="tiny">(подпись)</div>
        </td>
        <td style="width: 20%; text-align: center;">
            <div class="signature-line"></div>
            <div class="tiny">(ф.и.о.)</div>
        </td>
    </tr>
    <tr>
        <td colspan="5" class="tiny center">
            (основной государственный регистрационный номер индивидуального предпринимателя и дата присвоения такого номера)
        </td>
    </tr>
</table>

{{-- ===================== ОСНОВАНИЕ ПЕРЕДАЧИ ===================== --}}
<table class="mt-10" style="font-size: 8px; width: 100%;">
    <tr>
        <td style="width: 20%;">Основание передачи (сдачи)/получения приемки</td>
        <td style="width: 80%;"><span class="underline" style="width: 100%; display: block;">&nbsp;</span></td>
    </tr>
    <tr>
        <td colspan="2" class="tiny center">
            (договор; доверенность и др.)
        </td>
    </tr>
</table>

{{-- ===================== ДАННЫЕ О ТРАНСПОРТИРОВКЕ ===================== --}}
<table class="mt-10" style="font-size: 8px; width: 100%;">
    <tr>
        <td style="width: 20%;">Данные о транспортировке и грузе</td>
        <td style="width: 80%;"><span class="underline" style="width: 100%; display: block;">&nbsp;</span></td>
    </tr>
    <tr>
        <td colspan="2" class="tiny center">
            (транспортная накладная, поручение экспедитору, экспедиторская/складская расписка и др./масса нетто/брутто груза, если не приведены ссылки на транспортные документы, содержащие эти сведения)
        </td>
    </tr>
</table>

{{-- ===================== СДАЛ / ПРИНЯЛ ===================== --}}
<table class="mt-10" style="font-size: 8px; width: 100%;">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            <div>Товар (груз) передал/услуги, результаты работ, права сдал</div>
            <table class="mt-5" style="width: 100%;">
                <tr>
                    <td style="width: 50%;" class="center">
                        <div class="bold">{{ $sender['director_name'] }}</div>
                        <div class="tiny">(должность)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(подпись)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="bold">{{ $sender['director_name'] }}</div>
                        <div class="tiny">(ф.и.о.)</div>
                    </td>
                </tr>
            </table>
            <table class="mt-5" style="width: 100%;">
                <tr>
                    <td style="width: 30%;">Дата отгрузки, передачи (сдачи)</td>
                    <td style="width: 70%;" class="center">
                        « <span class="underline">{{ date('d') }}</span> »
                        <span class="underline">{{ date('m') }}</span>
                        20<span class="underline">{{ date('y') }}</span> г.
                    </td>
                </tr>
            </table>
            <div class="mt-5">Иные сведения об отгрузке, передаче</div>
            <div class="mt-5">Срок оплаты: <span class="underline">---</span></div>
            <div class="tiny mt-5">(ссылки на неотъемлемые приложения, сопутствующие документы, иные документы и т. п.)</div>
            <div class="tiny">(банковские реквизиты продавца)</div>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 10px;">
            <div>Товар (груз) получил/услуги, результаты работ, права принял</div>
            <table class="mt-5" style="width: 100%;">
                <tr>
                    <td style="width: 50%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(должность)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(подпись)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(ф.и.о.)</div>
                    </td>
                </tr>
            </table>
            <table class="mt-5" style="width: 100%;">
                <tr>
                    <td style="width: 40%;">Дата получения (приемки)</td>
                    <td style="width: 60%;" class="center">
                        « <span class="underline">&nbsp;&nbsp;&nbsp;</span> »
                        <span class="underline">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
                        20<span class="underline">&nbsp;&nbsp;</span> г.
                    </td>
                </tr>
            </table>
            <div class="mt-5">Иные сведения о получении, приемке</div>
            <div class="tiny mt-5">(информация о наличии/отсутствии претензии; ссылки на неотъемлемые приложения и другие документы и т. п.)</div>
            <div class="mt-5 small">
                Р/с {{ $sender['rs'] ?? '' }} в {{ $sender['bank_name'] ?? '' }}, БИК {{ $sender['bik'] ?? '' }}
            </div>
            <div class="tiny">(банковские реквизиты покупателя)</div>
        </td>
    </tr>
</table>

{{-- ===================== ОТВЕТСТВЕННЫЕ ЛИЦА ===================== --}}
<table class="mt-10" style="font-size: 8px; width: 100%;">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            <div>Ответственный за правильность оформления факта хозяйственной жизни</div>
            <table class="mt-5" style="width: 100%;">
                <tr>
                    <td style="width: 50%;" class="center">
                        <div class="bold">{{ $signatory->director_position ?? '' }}</div>
                        <div class="tiny">(должность)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(подпись)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="bold">{{ $signatory->director_name ?? '' }}</div>
                        <div class="tiny">(ф.и.о.)</div>
                    </td>
                </tr>
            </table>
            <div class="mt-5">Наименование экономического субъекта — составителя документа (в т. ч. комиссионера/агента)</div>
            <div class="bold">{{ $sender['name'] ?? '' }}</div>
            <div class="tiny">(может не заполняться при проставлении печати в М. П., может быть указан ИНН/КПП)</div>
            <div class="mt-10 bold">М.П.</div>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 10px;">
            <div>Ответственный за правильность оформления факта хозяйственной жизни</div>
            <table class="mt-5" style="width: 100%;">
                <tr>
                    <td style="width: 50%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(должность)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(подпись)</div>
                    </td>
                    <td style="width: 25%;" class="center">
                        <div class="signature-line"></div>
                        <div class="tiny">(ф.и.о.)</div>
                    </td>
                </tr>
            </table>
            <div class="mt-5">Наименование экономического субъекта — составителя документа</div>
            <div class="bold">{{ $reciever['name'] ?? '' }}</div>
            <div class="tiny">(может не заполняться при проставлении печати в М. П., может быть указан ИНН/КПП)</div>
            <div class="mt-10 bold">М.П.</div>
        </td>
    </tr>
</table>

</body>
</html>
