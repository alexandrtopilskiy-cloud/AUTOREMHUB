<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RepairStage;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestApproval;
use App\Models\RequestEvent;
use App\Models\RequestPart;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAcceptanceAct;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Закрытие ЗР (04.11.2026): В работе → ТС готово к выдаче Заказчику (акт
 * передачи ТС) → Выдан. Подготовка документов (7 документов УК) →
 * Подтверждение оплаты Заказчика → Оплата в СТО → Заявка на ремонт закрыта.
 */
final class ClosingRouteTest extends TestCase
{
    use \Tests\Concerns\StocksParts;

    use DatabaseTransactions;
    use InteractsWithApi;

    /** @var array<string, User> */
    private array $users = [];
    private RepairRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('media');
        // Отправка в ЭДО идёт очередью и в этом тесте не проверяется.
        Queue::fake();

        $tenant = Tenant::query()->create(['name' => 'Closing route']);
        $orgs = [];
        foreach (['manager' => 'ООО "УК"', 'customer' => 'ООО "Альфа"', 'station' => 'ООО "КУБ"'] as $role => $name) {
            $orgs[$role] = Organization::query()->create([
                'tenant_id' => $tenant->id, 'kind' => $role, 'name' => $name,
                'inn' => '77010000' . random_int(10, 99), 'vat_rate' => $role === 'station' ? null : 20,
            ]);
            $this->users[$role] = User::query()->create([
                'organization_id' => $orgs[$role]->id,
                'display_name'    => 'Пользователь ' . $role,
                'login'           => $role . Str::random(8),
                'password'        => bcrypt('test'),
                'role'            => $role,
                'active'          => true,
            ]);
        }
        $vehicle = Vehicle::query()->create([
            'tenant_id' => $tenant->id, 'organization_id' => $orgs['customer']->id,
            'name' => 'Lada Vesta', 'make' => 'Lada', 'model' => 'Vesta',
            'vin' => 'XTA00000000000001', 'plate' => 'А001АА77', 'plate_normalized' => 'А001АА77',
            'labor_hour_rate' => 1000,
        ]);
        $station = Station::query()->create([
            'tenant_id' => $tenant->id, 'organization_id' => $orgs['station']->id,
            'name' => 'СТО КУБ', 'agent_discount_percent' => 10,
        ]);
        $supplier = Supplier::query()->create(['tenant_id' => $tenant->id, 'name' => 'Поставщик', 'active' => true]);
        $workType = WorkType::query()->create(['tenant_id' => $tenant->id, 'name' => 'Замена масла', 'group_name' => 'Прочее', 'active' => true]);
        $catalog = CatalogPart::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Фильтр', 'article' => 'F-1', 'unit' => 'шт.',
            'manufacturer' => 'Test', 'active' => true,
        ]);

        $this->request = RepairRequest::query()->create([
            'public_id'                => (string) Str::uuid(),
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $orgs['customer']->id,
            'created_by'               => $this->users['customer']->id,
            'vehicle_id'               => $vehicle->id,
            'assigned_station_id'      => $station->id,
            'stage'                    => RepairStage::RepairInProgress,
            'revision'                 => 0,
            'estimate_version'         => 1,
            'planned_completion_at'    => now()->addDay(),
            'driver_snapshot'          => ['fullName' => 'Петров Пётр Петрович', 'phone' => '+7 (999) 111 22 33'],
            'data'                     => ['mileage' => '15000', 'laborRateType' => 'general'],
            'works'                    => [],
            'history'                  => [],
        ]);
        RequestStatusPeriod::query()->create([
            'repair_request_id' => $this->request->id,
            'stage'             => RepairStage::RepairInProgress->value,
            'entered_at'        => now(),
            'actor_id'          => $this->users['station']->id,
        ]);
        RequestWork::query()->create([
            'repair_request_id' => $this->request->id, 'name' => 'Замена масла', 'work_type_id' => $workType->id,
            'quantity' => 1, 'price' => 1000, 'hourly_rate' => 1000, 'norm_hours' => 1, 'norm_is_manual' => true,
            'norm_override_reason' => 'Ручная норма', 'line_amount' => 1000, 'agent_discount_percent' => 10,
        ]);
        RequestPart::query()->create([
            'repair_request_id' => $this->request->id, 'catalog_part_id' => $catalog->id, 'name' => 'Фильтр',
            'article' => 'F-1', 'unit' => 'шт.', 'supplier_id' => $supplier->id, 'quantity' => 2, 'price' => 250,
            'status' => 'received', 'approved_version' => 1, 'agent_discount_percent' => 10,
        ]);
        // Складской учёт: ЗЧ ЗР списываются со склада СТО при «ТС готово к выдаче».
        $this->stockPart($this->users['station'], $catalog, 2, $supplier);
        VehicleAcceptanceAct::query()->create([
            'repair_request_id' => $this->request->id, 'station_id' => $station->id,
            'created_by' => $this->users['station']->id, 'number' => $this->request->numberWithPrefix('APP'),
            'mode' => 'electronic', 'vehicle_snapshot' => ['vin' => $vehicle->vin], 'mileage' => 15100,
            'fuel_level' => 'half', 'condition' => 'Исправно', 'defect_types' => [], 'diagram_marks' => [],
            'locked_at' => now(),
        ]);
        $event = RequestEvent::query()->create([
            'uuid' => (string) Str::uuid(), 'repair_request_id' => $this->request->id,
            'actor_id' => $this->users['customer']->id, 'actor_label' => 'Заказчик', 'action' => 'next',
            'comment' => '', 'stage' => 3, 'revision' => 0, 'occurred_at' => now(),
        ]);
        RequestApproval::query()->create([
            'uuid' => (string) Str::uuid(), 'repair_request_id' => $this->request->id,
            'request_event_id' => $event->id, 'actor_id' => $this->users['customer']->id, 'stage' => 3,
            'decision' => 'accepted', 'comment' => '', 'estimate_version' => 1, 'snapshot' => [],
        ]);
    }

    public function test_full_closing_route(): void
    {
        // 1–3. СТО: «Далее» без заключения диагностики → «ТС готово к выдаче», акт передачи ТС.
        $this->actingAs($this->users['station']);
        $request = $this->get('/api/requests/' . $this->request->uuid)->assertOk()->json('request');
        $request = $this->api('POST', "/requests/{$request['id']}/next", [
            'revision' => $request['revision'], 'comment' => 'Работы выполнены', 'attachmentIds' => [],
        ])->assertOk()->json('request');

        $this->assertSame(RepairStage::Ready->value, $request['stage']);
        $this->assertSame('ТС готово к выдаче Заказчику', $request['statusLabel']);
        $act = $request['closing']['transferAct'];
        $this->assertSame('APP-' . str_pad((string) $this->request->id, 6, '0', STR_PAD_LEFT) . '/2', $act['number']);
        $this->assertSame('Петров Пётр Петрович', $act['driver']['fullName']);
        $this->assertSame('DV-' . str_pad((string) $this->request->id, 6, '0', STR_PAD_LEFT), $act['powerOfAttorney']['number']);
        $this->assertTrue($request['permissions']['canSignTransferAct']);
        $this->assertSame([], $request['closing']['documents']);

        // 4. Без подписанного акта дальше нельзя.
        $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertStatus(422);

        $request = $this->call('POST', "/api/requests/{$request['id']}/attachments",
            ['revision' => (string) $request['revision'], 'purpose' => 'transfer_act'], [],
            ['file' => UploadedFile::fake()->createWithContent('act.pdf', "%PDF-1.4\n%%EOF")],
            ['HTTP_ACCEPT' => 'application/json'])->assertCreated()->json('request');
        $scan = collect($request['attachments'])->firstWhere('purpose', 'transfer_act');

        $this->api('POST', "/requests/{$request['id']}/transfer-act/signed", [
            'revision' => $request['revision'], 'attachmentId' => $scan['id'], 'driverDocumentsVerified' => false,
        ])->assertStatus(422);
        $request = $this->api('POST', "/requests/{$request['id']}/transfer-act/signed", [
            'revision' => $request['revision'], 'attachmentId' => $scan['id'], 'driverDocumentsVerified' => true,
        ])->assertOk()->json('request');
        $this->assertTrue($request['closing']['transferAct']['driverDocumentsVerified']);

        $request = $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertOk()->json('request');
        $this->assertSame(RepairStage::Issued->value, $request['stage']);
        $this->assertFalse($request['permissions']['canAdvance']);
        // СТО видит только свои документы (СТО → УК, с агентской скидкой).
        $this->assertSame(['station_work_order', 'station_upd', 'station_invoice'], array_column($request['closing']['documents'], 'type'));
        $this->assertEquals([1350, 1350, 1350], array_column($request['closing']['documents'], 'amount'));

        // 5. УК: 7 документов.
        $this->actingAs($this->users['manager']);
        $request = $this->get("/api/requests/{$request['id']}")->assertOk()->json('request');
        $docs = collect($request['closing']['documents'])->keyBy('type');
        $this->assertCount(7, $docs);
        $suffix = str_pad((string) $this->request->id, 6, '0', STR_PAD_LEFT);
        $this->assertSame("ZN-{$suffix}/1", $docs['station_work_order']['number']);
        $this->assertSame("UPD-{$suffix}/2", $docs['customer_upd']['number']);
        $this->assertSame("SCH-{$suffix}/2", $docs['customer_invoice']['number']);
        $this->assertSame("AO-{$suffix}", $docs['agent_report']['number']);
        $this->assertEquals(1350, $docs['station_invoice']['amount']);
        $this->assertEquals(1500, $docs['customer_invoice']['amount']);
        $this->assertEquals(250, $docs['customer_invoice']['vatAmount']);
        $this->assertStringContainsString("ZN-{$suffix}/1", $docs['station_upd']['basis']);
        $this->get("/api/request-documents/{$docs['customer_upd']['id']}/pdf")->assertOk();

        // Заказчик не видит документы СТО → УК.
        $this->actingAs($this->users['customer']);
        $customer = $this->get("/api/requests/{$request['id']}")->assertOk()->json('request');
        $this->assertSame(['customer_work_order', 'customer_upd', 'customer_invoice', 'agent_report'], array_column($customer['closing']['documents'], 'type'));
        $this->get("/api/request-documents/{$docs['station_invoice']['id']}/pdf")->assertNotFound();
        $this->assertFalse(collect($customer['attachments'])->contains('purpose', 'generated_document'));

        // 6. УК: «Далее» → Подтверждение оплаты Заказчика.
        $this->actingAs($this->users['manager']);
        $request = $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertOk()->json('request');
        $this->assertSame(RepairStage::CustomerPayment->value, $request['stage']);

        // 7. Без отметки об оплате дальше нельзя.
        $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertStatus(422);
        $this->api('POST', "/requests/{$request['id']}/payments/customer", [
            'revision' => $request['revision'], 'paidAt' => now()->toDateString(), 'amount' => '1000',
        ])->assertStatus(422);
        // Удалить оплату нельзя, пока она не принята; оплату СТО на этапе 8 — нельзя.
        $this->api('DELETE', "/requests/{$request['id']}/payments/customer", ['revision' => $request['revision']])
            ->assertStatus(422);
        $request = $this->api('POST', "/requests/{$request['id']}/payments/customer", [
            'revision' => $request['revision'], 'paidAt' => now()->toDateString(), 'amount' => '1500',
        ])->assertOk()->json('request');
        $this->api('DELETE', "/requests/{$request['id']}/payments/station", ['revision' => $request['revision']])
            ->assertForbidden();

        // «Удалить оплату» снимает записи, сформированные при «Принять оплату».
        $request = $this->api('DELETE', "/requests/{$request['id']}/payments/customer", ['revision' => $request['revision']])
            ->assertOk()->json('request');
        $invoice = collect($request['closing']['documents'])->firstWhere('type', 'customer_invoice');
        $this->assertNull($invoice['paidAt']);
        $this->assertNull($invoice['paidAmount']);
        $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertStatus(422);

        $request = $this->api('POST', "/requests/{$request['id']}/payments/customer", [
            'revision' => $request['revision'], 'paidAt' => now()->toDateString(), 'amount' => '1500',
        ])->assertOk()->json('request');
        $request = $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertOk()->json('request');
        $this->assertSame(RepairStage::StationPayment->value, $request['stage']);

        // 8. Оплата в СТО → заявка закрыта.
        $request = $this->api('POST', "/requests/{$request['id']}/payments/station", [
            'revision' => $request['revision'], 'paidAt' => now()->toDateString(), 'amount' => '1350',
        ])->assertOk()->json('request');
        // На этапе 9 оплату Заказчика уже не удалить; оплату СТО — можно.
        $this->api('DELETE', "/requests/{$request['id']}/payments/customer", ['revision' => $request['revision']])
            ->assertForbidden();
        $request = $this->api('DELETE', "/requests/{$request['id']}/payments/station", ['revision' => $request['revision']])
            ->assertOk()->json('request');
        $this->assertNull(collect($request['closing']['documents'])->firstWhere('type', 'station_invoice')['paidAt']);
        $request = $this->api('POST', "/requests/{$request['id']}/payments/station", [
            'revision' => $request['revision'], 'paidAt' => now()->toDateString(), 'amount' => '1350',
        ])->assertOk()->json('request');
        $request = $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertOk()->json('request');
        $this->assertSame(RepairStage::Closed->value, $request['stage']);
        $this->assertSame('Заявка на ремонт закрыта', $request['statusLabel']);

        // 9. Отчёт.
        $rows = $this->get('/api/reports/requests')->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(1350.0, (float) $rows[0]['stationAmount']);
        $this->assertNotNull($rows[0]['transferredAt']);
        $this->assertNotNull($rows[0]['issuedAt']);
        $this->assertNotNull($rows[0]['customerPaidAt']);
        $this->assertSame('ООО "Альфа"', $rows[0]['customer']);

        $this->actingAs($this->users['customer']);
        $row = $this->get('/api/reports/requests')->assertOk()->json('rows.0');
        $this->assertArrayNotHasKey('agentDiscount', $row);
        $this->assertArrayNotHasKey('stationAmount', $row);

        $this->actingAs($this->users['station']);
        $row = $this->get('/api/reports/requests')->assertOk()->json('rows.0');
        $this->assertNull($row['customer']);
        $this->assertNull($row['customerInvoice']);
        $this->assertSame(now()->toDateString(), $row['customerPaidAt']);
        $this->assertNotNull($row['stationPaidAt']);
        $this->assertSame(150.0, (float) $row['agentDiscount']);

        // 10. ДДС: оплаты из «Взаиморасчётов» ЗР с точки зрения каждой организации.
        $this->actingAs($this->users['manager']);
        $flow = $this->get('/api/reports/cash-flow')->assertOk()->json();
        $this->assertCount(2, $flow['rows']);
        $byArticle = array_column($flow['rows'], null, 'article');
        $this->assertSame('in', $byArticle['customer_payment']['direction']);
        $this->assertSame(1500.0, (float) $byArticle['customer_payment']['amount']);
        $this->assertSame('ООО "Альфа"', $byArticle['customer_payment']['counterparty']);
        $this->assertSame('out', $byArticle['station_payment']['direction']);
        $this->assertSame(1350.0, (float) $byArticle['station_payment']['amount']);
        $this->assertSame($request['number'], $byArticle['station_payment']['requestNumber']);
        $this->assertContains('receipt_payment', array_column($flow['articles'], 'value'));

        $this->actingAs($this->users['station']);
        $rows = $this->get('/api/reports/cash-flow')->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(['in', 'station_payment', 1350.0, ''], [$rows[0]['direction'], $rows[0]['article'], (float) $rows[0]['amount'], $rows[0]['customer']]);

        $this->actingAs($this->users['customer']);
        $rows = $this->get('/api/reports/cash-flow')->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(['out', 'customer_payment', 1500.0], [$rows[0]['direction'], $rows[0]['article'], (float) $rows[0]['amount']]);
    }

    public function test_developer_deletes_closed_request_with_files_and_records(): void
    {
        $this->test_full_closing_route();
        $uuid = $this->request->uuid;
        $id = $this->request->id;

        // Чат с файлом и строка Рекомендаций из этой ЗР.
        $this->actingAs($this->users['manager']);
        $this->api('POST', "/requests/{$uuid}/chat")->assertSuccessful();
        $this->call('POST', "/api/requests/{$uuid}/chat/messages", ['text' => 'Фото'], [],
            ['files' => [UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF")]],
            ['HTTP_ACCEPT' => 'application/json'])->assertSuccessful();
        $recommendation = \App\Models\Recommendation::query()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->request->tenant_id,
            'vehicle_id' => $this->request->vehicle_id, 'customer_organization_id' => $this->request->customer_organization_id,
            'source_request_id' => $id, 'vin' => 'XTA00000000000001',
        ]);
        \Illuminate\Support\Facades\DB::table('recommendation_items')->insert([
            'uuid' => (string) Str::uuid(), 'recommendation_id' => $recommendation->id, 'kind' => 'work',
            'snapshot' => '{}', 'display' => '{}', 'actor_label' => 'УК', 'source_request_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $files = array_merge(
            \App\Models\Attachment::query()->where('repair_request_id', $id)->get(['disk', 'object_key'])->map(fn ($a) => [$a->disk, $a->object_key])->all(),
            \Illuminate\Support\Facades\DB::table('request_chat_files')->get(['disk', 'object_key'])->map(fn ($a) => [$a->disk, $a->object_key])->all(),
        );
        $this->assertNotEmpty($files);
        foreach ($files as [$disk, $key]) {
            Storage::disk($disk)->assertExists($key);
        }
        $this->assertTrue(\App\Models\StockMovement::query()->where('source_type', 'repair_request')->where('source_id', $id)->exists());

        // Только УК видит меню и может удалять.
        $this->assertTrue($this->get('/api/session')->json('user.permissions.canUseDeveloperTools'));
        $this->actingAs($this->users['station']);
        $this->assertFalse($this->get('/api/session')->json('user.permissions.canUseDeveloperTools'));
        $this->get('/api/developer/requests')->assertForbidden();
        $this->api('POST', '/developer/requests/delete', ['ids' => [$uuid]])->assertForbidden();

        $this->actingAs($this->users['manager']);
        // «Прочитать все уведомления во всех ЛК».
        foreach ($this->users as $role => $recipient) {
            \App\Models\RequestNotification::query()->create([
                'uuid' => (string) Str::uuid(), 'user_id' => $recipient->id, 'repair_request_id' => $id,
                'kind' => 'test', 'title' => 'Тест', 'message' => $role,
            ]);
        }
        $stats = $this->get('/api/developer/notifications')->assertOk()->json();
        $this->assertGreaterThanOrEqual(3, $stats['total']);
        $this->assertEqualsCanonicalizing(['customer', 'manager', 'station'], array_unique(array_column($stats['users'], 'role')));
        $this->assertSame($stats['total'], $this->api('POST', '/developer/notifications/read-all')->assertOk()->json('read'));
        $this->assertSame(0, $this->get('/api/developer/notifications')->json('total'));
        $this->actingAs($this->users['customer']);
        $this->assertSame([], $this->get('/api/notifications')->json('notifications'));
        $this->api('POST', '/developer/notifications/read-all')->assertForbidden();

        $this->actingAs($this->users['manager']);
        $list = $this->get('/api/developer/requests')->assertOk()->json('requests');
        $this->assertSame([$uuid], array_column($list, 'id'));
        $this->assertSame(count($files), $list[0]['files']);

        $result = $this->api('POST', '/developer/requests/delete', ['ids' => [$uuid]])->assertOk()->json();
        $this->assertSame([$uuid], $result['deletedIds']);
        $this->assertSame(count($files), $result['files']);

        $this->assertNull(RepairRequest::query()->find($id));
        foreach ([
            'attachments', 'request_documents', 'invoices', 'vehicle_acceptance_acts', 'work_orders',
            'vehicle_service_records', 'request_events', 'request_approvals', 'request_works', 'request_parts', 'request_chats',
            'request_notifications', 'request_status_periods',
        ] as $table) {
            $this->assertSame(0, \Illuminate\Support\Facades\DB::table($table)->where('repair_request_id', $id)->count(), $table);
        }
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('request_chat_files')->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('edo_documents')->where('source_type', RepairRequest::class)->where('source_id', $id)->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('recommendation_items')->where('source_request_id', $id)->count());
        $this->assertNull(\App\Models\Recommendation::query()->find($recommendation->id));
        $this->assertFalse(\App\Models\StockMovement::query()->where('source_type', 'repair_request')->where('source_id', $id)->exists());
        foreach ($files as [$disk, $key]) {
            Storage::disk($disk)->assertMissing($key);
        }
        $this->get("/api/requests/{$uuid}")->assertNotFound();
        $this->assertSame([], $this->get('/api/developer/requests')->json('requests'));
        $this->api('POST', '/developer/requests/delete', ['ids' => [$uuid]])->assertNotFound();
        $this->api('POST', '/developer/requests/delete', ['ids' => []])->assertStatus(422);
    }

    public function test_report_excludes_requests_before_customer_payment(): void
    {
        $this->actingAs($this->users['manager']);
        $this->assertSame([], $this->get('/api/reports/requests')->assertOk()->json('rows'));
    }
}
