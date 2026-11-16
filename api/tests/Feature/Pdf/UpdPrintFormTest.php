<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class UpdPrintFormTest extends TestCase
{
    public function test_upd_standard_form_renders_in_landscape_with_edo_stamp(): void
    {
        $party = fn (string $name, string $inn): array => [
            'name' => $name, 'inn' => $inn, 'kpp' => '771301001', 'ogrn' => '1157746123456',
            'address' => 'г. Москва, ул. Тестовая, д. 1', 'director' => 'Иванов И. И.',
            'bank' => 'АО Банк', 'bik' => '044525225', 'account' => '40702810000000000001',
            'corrAccount' => '30101810400000000225', 'vatRate' => 20,
        ];
        $payload = [
            'seller' => $party('ООО «СТО»', '7713026678'),
            'buyer'  => $party('ООО «УК»', '7701234567'),
            'rows'   => [
                ['kind' => 'work', 'name' => 'Замена масла ДВС', 'quantity' => 1, 'unit' => 'шт', 'price' => 1500, 'amount' => 1800, 'vatRate' => 20, 'vat' => 300],
                ['kind' => 'part', 'name' => 'Фильтр масляный', 'quantity' => 2, 'unit' => 'шт', 'price' => 500, 'amount' => 1200, 'vatRate' => 20, 'vat' => 200],
            ],
            'totals'  => ['amount' => 3000, 'vat' => 500],
            'basis'   => 'Заказ-наряд № ZN-000001/1 от 16.11.2026',
            'request' => ['number' => 'ZR-000001', 'vehicle' => 'BMW 530i', 'plate' => 'А123ВС77'],
        ];
        $doc = (object) ['number' => 'UPD-000001/1', 'document_date' => CarbonImmutable::parse('2026-11-16')];
        $edo = [
            'provider' => 'СБИС', 'name' => 'УПД', 'number' => 'UPD-000001/1', 'date' => '16.11.2026',
            'sum' => '3000.00', 'sender' => 'ООО «СТО»', 'receiver' => 'ООО «УК»', 'externalId' => 'x',
            'state' => 'Отправлен', 'readAt' => '16.11.2026 10:00', 'source' => 'СБИС',
            'signatures' => [['owner' => 'Иванов И. И.', 'position' => 'Директор', 'organization' => 'ООО «СТО»', 'inn' => '7713026678', 'file' => 'upd.xml']],
            'events' => [['at' => '16.11.2026', 'name' => 'Отправка']], 'notice' => null,
        ];

        $html = view('pdf.closing.upd', ['doc' => $doc, 'title' => 'УПД', 'payload' => $payload, 'side' => 'station', 'edo' => $edo])->render();
        $this->assertStringContainsString('Универсальный<br>передаточный<br>документ', $html);
        $this->assertStringContainsString('Счет-фактура № UPD-000001/1', $html);
        $this->assertStringContainsString('Иванов И. И.', $html);

        $withoutStamp = view('pdf.closing.upd', ['doc' => $doc, 'title' => 'УПД', 'payload' => $payload, 'side' => 'station'])->render();
        $this->assertStringContainsString('Фильтр масляный', $withoutStamp);

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape')->setOption('defaultFont', 'DejaVu Sans')->output();
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
