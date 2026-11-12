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

use App\Models\StockMovement;
use App\Services\StockDocumentService;
use App\Services\WarehouseService;
use App\Exceptions\ApiException;

/**
 * Складской учёт (10.11.2026): склады и наценка, Приход/Реализация,
 * резерв ЗЧ в ЗР, блокировка перехода на «ТС готово к выдаче» при нехватке
 * ЗЧ на складе СТО, списание и отчёт «Остатки на складе».
 */
final class WarehouseTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithApi;
    use \Tests\Concerns\StocksParts;

    /** @var array<string, User> */
    private array $users = [];
    /** @var array<string, Organization> */
    private array $orgs = [];
    private RepairRequest $request;
    private CatalogPart $catalog;
    private Supplier $supplier;
    private Vehicle $vehicle;

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
        $this->catalog = $catalog;
        $this->supplier = $supplier;
        $this->orgs = $orgs;
        $this->vehicle = $vehicle;
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

    private function documents(): StockDocumentService
    {
        return app(StockDocumentService::class);
    }

    private function warehouseId(User $user, string $name = 'Основной склад'): string
    {
        $rows = app(WarehouseService::class)->saveWarehouse($user, null, ['name' => $name]);

        return collect($rows)->firstWhere('name', $name)['id'];
    }

    public function test_settings_warehouses_and_markup(): void
    {
        $this->actingAs($this->users['station']);
        $this->postJson('/api/warehouse/warehouses', ['name' => 'Основной'])->assertCreated()->assertJsonPath('warehouses.0.name', 'Основной');
        $this->postJson('/api/warehouse/warehouses', ['name' => 'основной'])->assertStatus(422);
        $id = $this->getJson('/api/warehouse/settings')->assertOk()->json('warehouses.0.id');
        $this->patchJson('/api/warehouse/warehouses/' . $id, ['name' => 'Склад №1'])->assertOk()->assertJsonPath('warehouses.0.name', 'Склад №1');

        $this->putJson('/api/warehouse/settings/markup', ['mode' => 'flat', 'percent' => 30, 'tiers' => []])->assertOk()->assertJsonPath('markup.percent', 30);
        $service = app(WarehouseService::class);
        $this->assertSame(130.0, $service->salePrice($this->orgs['station']->id, 100));

        $tiers = [
            ['from' => 0, 'to' => 1000, 'percent' => 50],
            ['from' => 1000.01, 'to' => null, 'percent' => 20],
        ];
        $this->putJson('/api/warehouse/settings/markup', ['mode' => 'progressive', 'percent' => null, 'tiers' => $tiers])->assertOk();
        $this->assertSame(750.0, $service->salePrice($this->orgs['station']->id, 500));
        $this->assertSame(2400.0, $service->salePrice($this->orgs['station']->id, 2000));

        $overlap = [['from' => 0, 'to' => 1000, 'percent' => 50], ['from' => 500, 'to' => null, 'percent' => 20]];
        $this->putJson('/api/warehouse/settings/markup', ['mode' => 'progressive', 'percent' => null, 'tiers' => $overlap])->assertStatus(422);
        $eleven = array_map(fn ($i) => ['from' => $i * 10, 'to' => $i * 10 + 9, 'percent' => 10], range(0, 10));
        $this->putJson('/api/warehouse/settings/markup', ['mode' => 'progressive', 'percent' => null, 'tiers' => $eleven])->assertStatus(422);

        // Склады организации не видны другим организациям; Заказчику раздел недоступен.
        $this->actingAs($this->users['manager'])->getJson('/api/warehouse/settings')->assertOk()->assertJsonCount(0, 'warehouses');
        $this->actingAs($this->users['customer'])->getJson('/api/warehouse/settings')->assertForbidden();
    }

    public function test_receipt_applies_markup_on_save_and_posts_to_stock(): void
    {
        $user = $this->users['station'];
        app(WarehouseService::class)->saveMarkup($user, ['mode' => 'flat', 'percent' => 25, 'tiers' => []]);
        $warehouse = $this->warehouseId($user);
        $doc = $this->documents()->save($user, null, [
            'type' => 'receipt', 'documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouse,
            'supplierId' => $this->supplier->uuid,
            'items' => [
                ['catalogPartId' => $this->catalog->uuid, 'qty' => 3, 'purchasePrice' => 200, 'salePrice' => null],
            ],
        ]);
        $this->assertSame('PRH-000001', $doc['number']);
        $this->assertSame('created', $doc['status']);
        $this->assertSame(250.0, $doc['items'][0]['salePrice']);
        $this->assertSame(600.0, $doc['items'][0]['amount']);
        $this->assertSame($user->uuid, $doc['items'][0]['managerId']);
        $this->assertSame(0, StockMovement::query()->count(), 'Созданный Приход не добавляет ЗЧ на склад');

        $posted = $this->documents()->post($user, $doc['id']);
        $this->assertSame('Проведен', $posted['statusLabel']);
        $this->assertSame(3.0, (float) StockMovement::query()->sum('quantity'));

        try {
            $this->documents()->save($user, $doc['id'], ['documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouse, 'supplierId' => $this->supplier->uuid, 'items' => []]);
            $this->fail('Проведённый документ не редактируется');
        } catch (ApiException $error) {
            $this->assertSame(422, $error->status);
        }

        $this->documents()->unpost($user, $doc['id']);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_receipt_and_sale_accept_partial_payments(): void
    {
        $user = $this->users['station'];
        $warehouse = $this->warehouseId($user);
        $receipt = $this->documents()->save($user, null, [
            'type' => 'receipt', 'documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouse,
            'supplierId' => $this->supplier->uuid,
            'items' => [['catalogPartId' => $this->catalog->uuid, 'qty' => 4, 'purchasePrice' => 250, 'salePrice' => 300]],
        ]);
        $this->assertSame('unpaid', $receipt['paymentStatus']);
        $this->assertSame(1000.0, $receipt['debt']);

        $paid = $this->documents()->addPayment($user, $receipt['id'], ['paidOn' => now()->format('Y-m-d'), 'amount' => 400, 'method' => 'bank', 'comment' => 'п/п 15']);
        $this->assertSame('partial', $paid['paymentStatus']);
        $this->assertSame('Оплачен частично', $paid['paymentStatusLabel']);
        $this->assertSame(600.0, $paid['debt']);
        $this->assertSame('Безналичный расчёт', $paid['payments'][0]['methodLabel']);

        try {
            $this->documents()->addPayment($user, $receipt['id'], ['paidOn' => now()->format('Y-m-d'), 'amount' => 700, 'method' => 'cash']);
            $this->fail('Оплата больше остатка не принимается');
        } catch (ApiException $error) {
            $this->assertStringContainsString('больше остатка', $error->getMessage());
        }
        try {
            $this->documents()->delete($user, $receipt['id']);
            $this->fail('Документ с оплатами не удаляется');
        } catch (ApiException $error) {
            $this->assertStringContainsString('есть оплаты', $error->getMessage());
        }
        try {
            $this->documents()->save($user, $receipt['id'], [
                'documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouse, 'supplierId' => $this->supplier->uuid,
                'items' => [['catalogPartId' => $this->catalog->uuid, 'qty' => 1, 'purchasePrice' => 250, 'salePrice' => 300]],
            ]);
            $this->fail('Сумма документа не может стать меньше оплаченной');
        } catch (ApiException $error) {
            $this->assertStringContainsString('меньше уже оплаченной', $error->getMessage());
        }

        $full = $this->documents()->addPayment($user, $receipt['id'], ['paidOn' => now()->format('Y-m-d'), 'amount' => 600, 'method' => 'cash']);
        $this->assertSame('paid', $full['paymentStatus']);
        $this->assertSame(0.0, $full['debt']);
        $list = $this->documents()->index($user, 'receipt');
        $this->assertSame(1000.0, $list[0]['paid']);
        $this->assertSame('Оплачен', $list[0]['paymentStatusLabel']);

        $this->documents()->post($user, $receipt['id']);
        $sale = $this->documents()->save($user, null, [
            'type' => 'sale', 'documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouse,
            'customerId' => $this->orgs['customer']->uuid, 'vehicleId' => $this->vehicle->uuid,
            'items' => [['catalogPartId' => $this->catalog->uuid, 'qty' => 1, 'salePrice' => null]],
        ]);
        $sale = $this->documents()->addPayment($user, $sale['id'], ['paidOn' => now()->format('Y-m-d'), 'amount' => 300, 'method' => 'card']);
        $this->assertSame('paid', $sale['paymentStatus']);
        $afterDelete = $this->documents()->deletePayment($user, $sale['id'], $sale['payments'][0]['id']);
        $this->assertSame('unpaid', $afterDelete['paymentStatus']);
        $this->assertSame([], $afterDelete['payments']);

        // ДДС: оплаты Поставщику — расход, оплата Реализации — поступление.
        $this->documents()->addPayment($user, $sale['id'], ['paidOn' => now()->format('Y-m-d'), 'amount' => 200, 'method' => 'cash']);
        $this->actingAs($user);
        $rows = $this->get('/api/reports/cash-flow')->assertOk()->json('rows');
        $this->assertSame([400.0, 600.0, 200.0], array_map(fn ($row) => (float) $row['amount'], $rows));
        $this->assertSame(['out', 'out', 'in'], array_column($rows, 'direction'));
        $this->assertSame(['receipt_payment', 'receipt_payment', 'sale_payment'], array_column($rows, 'article'));
        $this->assertSame('Поставщик', $rows[0]['counterparty']);
        $this->assertSame('ООО "Альфа"', $rows[2]['counterparty']);
        $this->assertSame('Наличные', $rows[2]['method']);
        $this->assertSame($receipt['number'], $rows[0]['document']);

        // Заказчик видит оплату Реализации как расход; УК чужих оплат склада не видит.
        $this->actingAs($this->users['customer']);
        $rows = $this->get('/api/reports/cash-flow')->assertOk()->json('rows');
        $this->assertSame(['out', 'sale_payment', 'Покупка ЗЧ', 'ООО "КУБ"'], [$rows[0]['direction'], $rows[0]['article'], $rows[0]['articleLabel'], $rows[0]['counterparty']]);
        $this->actingAs($this->users['manager']);
        $this->assertSame([], $this->get('/api/reports/cash-flow')->assertOk()->json('rows'));

        // Меню «Разработчик» (только УК): удаление Приходов и Реализаций с оплатами и движениями.
        $this->documents()->post($user, $sale['id']);
        $stock = app(\App\Services\StockService::class);
        $this->assertSame(3.0, $stock->onHand($user->organization_id)[$this->catalog->id]);
        $this->actingAs($this->users['station']);
        $this->get('/api/developer/stock-documents')->assertForbidden();
        $this->api('POST', '/developer/stock-documents/delete', ['ids' => [$sale['id']]])->assertForbidden();
        $this->actingAs($this->users['manager']);
        $docs = collect($this->get('/api/developer/stock-documents')->assertOk()->json('documents'))->keyBy('id');
        $this->assertSame(['receipt', 'sale'], [$docs[$receipt['id']]['type'], $docs[$sale['id']]['type']]);
        $this->assertSame([1000.0, 1000.0, 2, true], [(float) $docs[$receipt['id']]['amount'], (float) $docs[$receipt['id']]['paid'], $docs[$receipt['id']]['payments'], $docs[$receipt['id']]['posted']]);
        $this->assertSame('ООО "Альфа"', $docs[$sale['id']]['counterparty']);

        $result = $this->api('POST', '/developer/stock-documents/delete', ['ids' => [$sale['id']]])->assertOk()->json();
        $this->assertSame([$sale['id']], $result['deletedIds']);
        $this->assertSame(1, $result['payments']);
        $this->assertSame(4.0, $stock->onHand($user->organization_id)[$this->catalog->id], 'Реализация отменена — ЗЧ вернулись');
        $this->api('POST', '/developer/stock-documents/delete', ['ids' => [$receipt['id']]])->assertOk();
        $this->assertSame([], $stock->onHand($user->organization_id));
        $this->assertSame([], $this->get('/api/developer/stock-documents')->json('documents'));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('stock_document_payments')->count());
        $this->api('POST', '/developer/stock-documents/delete', ['ids' => [$receipt['id']]])->assertNotFound();
    }

    public function test_cost_method_drives_prices_write_off_and_balance(): void
    {
        $user = $this->users['station'];
        $part = CatalogPart::query()->create([
            'tenant_id' => $this->catalog->tenant_id, 'name' => 'Колодки', 'article' => 'K-1', 'unit' => 'шт.',
            'manufacturer' => 'Test', 'active' => true,
        ]);
        $this->stockPart($user, $part, 2, $this->supplier, 100, 150);
        $this->stockPart($user, $part, 2, $this->supplier, 200, 300);
        $stock = app(\App\Services\StockService::class);
        $org = $this->orgs['station']->id;
        $warehouses = app(WarehouseService::class);

        $this->assertSame('average', $warehouses->settings($user)['costMethod']);
        $this->assertSame(['purchasePrice' => 150.0, 'salePrice' => 225.0], array_intersect_key($stock->nextPrices($org, [$part->id])[$part->id], ['purchasePrice' => 1, 'salePrice' => 1]));

        $warehouses->saveCostMethod($user, ['method' => 'fifo']);
        $this->assertSame(150.0, $stock->nextPrices($org, [$part->id])[$part->id]['salePrice'], 'ФИФО: цена ранней партии');
        $warehouses->saveCostMethod($user, ['method' => 'lifo']);
        $this->assertSame(300.0, $stock->nextPrices($org, [$part->id])[$part->id]['salePrice'], 'ЛИФО: цена поздней партии');

        // Реализация 1 шт. по ФИФО: себестоимость 100, следующая цена — снова ранняя партия, затем поздняя.
        $warehouses->saveCostMethod($user, ['method' => 'fifo']);
        $warehouse = app(WarehouseService::class)->warehouses($user)[0]['id'];
        $sale = $this->documents()->save($user, null, [
            'type' => 'sale', 'documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouse,
            'customerId' => $this->orgs['customer']->uuid, 'vehicleId' => $this->vehicle->uuid,
            'items' => [['catalogPartId' => $part->uuid, 'qty' => 2, 'salePrice' => null]],
        ]);
        $this->assertSame(150.0, $sale['items'][0]['salePrice']);
        $this->assertSame(300.0, $stock->nextPrices($org, [$part->id])[$part->id]['salePrice'], 'Ранняя партия зарезервирована');
        $this->documents()->post($user, $sale['id']);
        $this->assertSame(100.0, (float) StockMovement::query()->where('source_type', StockMovement::SOURCE_SALE)->where('catalog_part_id', $part->id)->value('unit_cost'));

        $balance = collect($this->documents()->balance($user, now(config('sto.service_timezone'))->format('Y-m-d'), null)['rows'])->firstWhere('article', 'K-1');
        $this->assertSame(2.0, $balance['balance']);
        $this->assertSame(400.0, $balance['amount'], 'ФИФО: в остатке поздняя партия по 200');
        $warehouses->saveCostMethod($user, ['method' => 'average']);
        $balance = collect($this->documents()->balance($user, now(config('sto.service_timezone'))->format('Y-m-d'), null)['rows'])->firstWhere('article', 'K-1');
        $this->assertSame(300.0, $balance['amount'], 'Средневзвешенная: 2 × 150');
    }

    public function test_sale_reserves_and_highlights_missing_parts(): void
    {
        $user = $this->users['station'];
        $this->stockPart($user, $this->catalog, 5, $this->supplier, 100, 180);
        $warehouse = app(WarehouseService::class)->warehouses($user)[0]['id'];
        $payload = fn (float $qty) => [
            'type' => 'sale', 'documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouse,
            'customerId' => $this->orgs['customer']->uuid, 'vehicleId' => $this->vehicle->uuid,
            'items' => [['catalogPartId' => $this->catalog->uuid, 'qty' => $qty, 'salePrice' => null]],
        ];

        // В ЗР (этап 5) уже зарезервировано 2 шт., на складе 5 → свободно 3.
        $sale = $this->documents()->save($user, null, $payload(3));
        $this->assertSame('RLZ-000001', $sale['number']);
        $this->assertSame(180.0, $sale['items'][0]['salePrice'], 'Цена реализации из последнего Прихода');
        $this->assertTrue($sale['items'][0]['sufficient']);
        $this->assertSame('В резерве', $sale['items'][0]['stockLabel']);

        $second = $this->documents()->save($user, null, $payload(1));
        $this->assertFalse($second['items'][0]['sufficient'], 'Строка без остатка выделяется');
        try {
            $this->documents()->post($user, $second['id']);
            $this->fail('Реализация без остатка не проводится');
        } catch (ApiException $error) {
            $this->assertStringContainsString('Недостаточно ЗЧ на складе', $error->getMessage());
        }

        $this->documents()->post($user, $sale['id']);
        $this->assertSame(2.0, (float) StockMovement::query()->where('organization_id', $this->orgs['station']->id)->sum('quantity'));
    }

    public function test_request_cannot_reach_ready_without_stock_and_writes_off_parts(): void
    {
        $this->actingAs($this->users['station']);
        $request = $this->get('/api/requests/' . $this->request->uuid)->assertOk()->json('request');
        $stock = $this->getJson("/api/requests/{$request['id']}/stock")->assertOk()->json();
        $partId = $request['parts'][0]['id'];
        $this->assertFalse($stock['rows'][$partId]['sufficient'], 'ЗЧ только в ЗР, на склад не добавлены');
        $this->assertSame(['Фильтр (F-1) — нужно 2, на складе доступно 0'], $request['stockShortage'], 'ЛК СТО видит, каких ЗЧ не хватает');
        $this->actingAs($this->users['manager']);
        $this->assertSame([], $this->get('/api/requests/' . $this->request->uuid)->assertOk()->json('request.stockShortage'));
        $this->actingAs($this->users['station']);

        $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => 'Готово', 'attachmentIds' => []])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Недостаточно ЗЧ на складе для их реализации через ЗР: Фильтр (F-1) — нужно 2, на складе доступно 0']);

        $this->stockPart($this->users['station'], $this->catalog, 2, $this->supplier);
        $stock = $this->getJson("/api/requests/{$request['id']}/stock")->assertOk()->json();
        $this->assertTrue($stock['rows'][$partId]['sufficient'], 'ЗЧ в резерве');
        $this->assertSame([], $this->get('/api/requests/' . $this->request->uuid)->assertOk()->json('request.stockShortage'));

        $request = $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => 'Готово', 'attachmentIds' => []])
            ->assertOk()->json('request');
        $this->assertSame(RepairStage::Ready->value, $request['stage']);
        $this->assertSame(-2.0, (float) StockMovement::query()->where('source_type', 'repair_request')->sum('quantity'));

        $report = $this->getJson('/api/warehouse/balance?date=' . now(config('sto.service_timezone'))->format('Y-m-d'))->assertOk()->json();
        $this->assertSame(2.0, (float) $report['rows'][0]['incoming']);
        $this->assertSame(2.0, (float) $report['rows'][0]['outgoing']);
        $this->assertSame(0.0, (float) $report['rows'][0]['balance']);
        $yesterday = $this->getJson('/api/warehouse/balance?date=' . now(config('sto.service_timezone'))->subDay()->format('Y-m-d'))->assertOk()->json();
        $this->assertSame([], $yesterday['rows']);

        // Возврат на «В работе» отменяет списание.
        $this->api('POST', "/requests/{$request['id']}/return", ['revision' => $request['revision'], 'comment' => 'Доработка', 'attachmentIds' => []])
            ->assertOk();
        $this->assertSame(0, StockMovement::query()->where('source_type', 'repair_request')->count());
    }

    public function test_stock_prices_for_station_chosen_in_unsaved_form(): void
    {
        $this->stockPart($this->users['station'], $this->catalog, 3, $this->supplier, 100, 180);
        $stationUuid = $this->request->stationRecord->uuid;
        $this->actingAs($this->users['manager']);

        // Новая ЗР: СТО выбрана в форме, ЗР ещё не сохранена.
        $stock = $this->getJson('/api/warehouse/station-stock?stationId=' . $stationUuid)->assertOk()->json();
        $this->assertTrue($stock['available']);
        $this->assertSame(180.0, (float) $stock['parts'][$this->catalog->uuid]['salePrice']);
        $this->assertFalse($this->getJson('/api/warehouse/station-stock')->assertOk()->json('available'), 'Без СТО остатков нет');

        // Сохранённая ЗР: СТО снята в форме — остатков нет, выбрана снова — есть.
        $this->assertFalse($this->getJson('/api/requests/' . $this->request->uuid . '/stock?stationId=')->assertOk()->json('available'));
        $stock = $this->getJson('/api/requests/' . $this->request->uuid . '/stock?stationId=' . $stationUuid)->assertOk()->json();
        $this->assertSame(180.0, (float) $stock['parts'][$this->catalog->uuid]['salePrice']);
    }

    public function test_each_organization_keeps_own_stock_and_numbering(): void
    {
        $warehouses = ['station' => $this->warehouseId($this->users['station']), 'manager' => $this->warehouseId($this->users['manager'])];
        $receipt = fn (User $user): array => $this->documents()->save($user, null, [
            'type' => 'receipt', 'documentDate' => now()->format('Y-m-d'), 'warehouseId' => $warehouses[$user->role->value === 'station' ? 'station' : 'manager'],
            'supplierId' => $this->supplier->uuid,
            'items' => [['catalogPartId' => $this->catalog->uuid, 'qty' => 1, 'purchasePrice' => 100, 'salePrice' => 150]],
        ]);
        $this->assertSame('PRH-000001', $receipt($this->users['station'])['number']);
        $this->assertSame('PRH-000001', $receipt($this->users['manager'])['number'], 'У УК свой счётчик');
        $this->assertSame('PRH-000002', $receipt($this->users['station'])['number']);

        $this->actingAs($this->users['manager']);
        $this->getJson('/api/warehouse/documents?type=receipt')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->users['station']);
        $this->getJson('/api/warehouse/documents?type=receipt')->assertOk()->assertJsonCount(2, 'data');
    }

    /** Перевод ЗР теста на этап (без прохождения маршрута). */
    private function moveTo(RepairStage $stage): void
    {
        RequestStatusPeriod::query()->where('repair_request_id', $this->request->id)->whereNull('exited_at')->update(['exited_at' => now()]);
        RequestStatusPeriod::query()->create([
            'repair_request_id' => $this->request->id, 'stage' => $stage->value,
            'entered_at' => now(), 'actor_id' => $this->users['manager']->id,
        ]);
        $this->request->forceFill(['stage' => $stage])->save();
        RequestPart::query()->where('repair_request_id', $this->request->id)->update(['status' => 'proposed', 'approved_version' => null]);
    }

    public function test_parts_from_manager_use_uk_stock_markup_and_no_agent_discount(): void
    {
        app(WarehouseService::class)->saveMarkup($this->users['manager'], ['mode' => 'flat', 'percent' => 20, 'tiers' => []]);
        $this->stockPart($this->users['manager'], $this->catalog, 1, $this->supplier, 100, 130);
        $this->moveTo(RepairStage::ManagerRouting);
        $this->actingAs($this->users['manager']);
        $request = $this->get('/api/requests/' . $this->request->uuid)->assertOk()->json('request');
        $this->assertFalse($request['partsFromManager']);
        $this->assertContains('partsFromManager', $request['permissions']['editableFields']);
        $this->assertSame(10.0, (float) $request['parts'][0]['agentDiscountPercent']);

        // Галочка ещё не сохранена: остатки и цены — по складу УК (закуп 100 + наценка УК 20 %).
        $stock = $this->getJson("/api/requests/{$request['id']}/stock?fromManager=1")->assertOk()->json();
        $this->assertSame('manager', $stock['source']);
        $this->assertSame(120.0, (float) $stock['parts'][$this->catalog->uuid]['salePrice']);

        $request = $this->patchRequest($request, ['partsFromManager' => true]);
        $this->assertTrue($request['partsFromManager']);
        $this->assertSame(0.0, (float) $request['parts'][0]['agentDiscountPercent'], 'Агентская скидка по ЗЧ не применяется');
        $this->assertSame(0.0, (float) $request['works'][0]['agentDiscountPercent'], 'Агентская скидка по работам не применяется');
        $this->assertSame(0.0, (float) $request['agentDiscount']['amount']);

        // Нужно 2, на складе УК 1 — строка не покрыта (во фронте тёмно-оранжевая).
        $stock = $this->getJson("/api/requests/{$request['id']}/stock")->assertOk()->json();
        $this->assertSame('manager', $stock['source']);
        $this->assertFalse($stock['rows'][$request['parts'][0]['id']]['sufficient']);
        $this->stockPart($this->users['manager'], $this->catalog, 1, $this->supplier, 100, 130);
        $stock = $this->getJson("/api/requests/{$request['id']}/stock")->assertOk()->json();
        $this->assertTrue($stock['rows'][$request['parts'][0]['id']]['sufficient'], 'ЗЧ в резерве на складе УК');

        // Галочка снята — скидка СТО возвращается.
        $request = $this->patchRequest($request, ['partsFromManager' => false]);
        $this->assertSame(10.0, (float) $request['parts'][0]['agentDiscountPercent']);
        $this->assertSame('station', $this->getJson("/api/requests/{$request['id']}/stock")->assertOk()->json('source'));
    }

    public function test_station_accepts_parts_from_manager_with_transfer_document(): void
    {
        app(WarehouseService::class)->saveMarkup($this->users['manager'], ['mode' => 'flat', 'percent' => 20, 'tiers' => []]);
        $this->stockPart($this->users['manager'], $this->catalog, 5, $this->supplier, 100, 130);
        $this->moveTo(RepairStage::WaitingVehicle);
        $this->request->forceFill(['parts_from_manager' => true])->save();
        RequestPart::query()->where('repair_request_id', $this->request->id)->update(['agent_discount_percent' => 0]);

        $this->actingAs($this->users['station']);
        $settings = $this->getJson('/api/warehouse/settings')->assertOk()->json('warehouses');
        $system = collect($settings)->firstWhere('system', true);
        $this->assertSame('Склад от УК', $system['name']);
        $this->patchJson('/api/warehouse/warehouses/' . $system['id'], ['name' => 'Другой'])->assertStatus(422);
        $this->deleteJson('/api/warehouse/warehouses/' . $system['id'])->assertStatus(422);

        $request = $this->get('/api/requests/' . $this->request->uuid)->assertOk()->json('request');
        $this->assertTrue($request['permissions']['canAcceptManagerParts']);
        $this->assertSame([], $request['stockShortage'], 'До приёма ЗЧ лежат на складе УК');
        $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Примите ЗЧ от УК: нажмите «Принять ЗЧ от УК» на вкладке «Работы и ЗЧ»']);

        $request = $this->api('POST', "/requests/{$request['id']}/manager-parts/accept", ['revision' => $request['revision']])
            ->assertOk()->json('request');
        $this->assertSame('PRM-000001', $request['partsTransfer']['number']);
        $this->assertFalse($request['permissions']['canAcceptManagerParts']);

        $managerOrg = $this->orgs['manager']->id;
        $stationOrg = $this->orgs['station']->id;
        $stock = app(\App\Services\StockService::class);
        $this->assertSame(3.0, $stock->onHand($managerOrg)[$this->catalog->id], 'Списано со склада УК');
        $target = \App\Models\Warehouse::query()->where('organization_id', $stationOrg)->where('system_code', 'from_manager')->firstOrFail();
        $this->assertSame(2.0, $stock->onHand($stationOrg, [], $target->id)[$this->catalog->id], 'Оприходовано на «Склад от УК»');
        $incoming = StockMovement::query()->where('organization_id', $stationOrg)->where('source_type', 'transfer')->firstOrFail();
        $this->assertSame(120.0, (float) $incoming->unit_cost, 'Цена Перемещения = закуп УК 100 + наценка УК 20 %, без наценки СТО');
        $doc = \App\Models\StockDocument::query()->where('type', 'transfer')->firstOrFail();
        $this->assertSame(100.0, (float) $doc->items()->first()->purchase_price, 'Себестоимость УК');
        $this->assertSame(120.0, (float) $doc->items()->first()->sale_price);
        $this->assertSame([], $stock->missingForRequest($this->request->refresh()), 'ЗЧ ЗР теперь покрыты складом СТО');

        // Закрывающие документы: ЗЧ от УК реализует УК — у СТО только работы.
        $docs = collect(app(\App\Services\ClosingDocumentService::class)->generateClosingDocuments($this->request->refresh(), $this->users['manager']))->keyBy(fn ($doc) => $doc->type instanceof \BackedEnum ? $doc->type->value : $doc->type);
        foreach (['station_work_order', 'station_upd', 'station_invoice'] as $type) {
            $this->assertSame(['work'], array_values(array_unique(array_column($docs[$type]->payload['rows'], 'kind'))), $type);
        }
        foreach (['customer_work_order', 'customer_upd', 'customer_invoice'] as $type) {
            $this->assertContains('part', array_column($docs[$type]->payload['rows'], 'kind'), $type);
        }
        $this->assertSame(900.0, (float) $docs['station_invoice']->amount, 'СТО → УК: работа 1000 за вычетом агентской скидки 10 %');
        $this->assertSame(900.0, \App\Support\AgentDiscount::summary($this->request->refresh())['stationAmount'], 'К оплате СТО — без ЗЧ от УК');

        $this->api('POST', "/requests/{$request['id']}/manager-parts/accept", ['revision' => $request['revision']])->assertForbidden();
        $this->api('POST', "/requests/{$request['id']}/next", ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])
            ->assertOk()->assertJsonPath('request.stage', RepairStage::RepairInProgress->value);

        // Меню «Разработчик»: удаление ЗР отменяет Перемещение — ЗЧ возвращаются на склад УК.
        $this->actingAs($this->users['manager']);
        $this->api('POST', '/developer/requests/delete', ['ids' => [$this->request->uuid]])->assertOk();
        $this->assertSame(5.0, $stock->onHand($managerOrg)[$this->catalog->id]);
        $this->assertSame([], $stock->onHand($stationOrg, [], $target->id));
        $this->assertFalse(\App\Models\StockDocument::query()->where('type', 'transfer')->exists());
    }

    public function test_station_cannot_accept_parts_missing_on_uk_stock(): void
    {
        $this->stockPart($this->users['manager'], $this->catalog, 1, $this->supplier);
        $this->moveTo(RepairStage::WaitingVehicle);
        $this->request->forceFill(['parts_from_manager' => true])->save();
        $this->actingAs($this->users['station']);
        $request = $this->get('/api/requests/' . $this->request->uuid)->assertOk()->json('request');
        $this->api('POST', "/requests/{$request['id']}/manager-parts/accept", ['revision' => $request['revision']])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Недостаточно ЗЧ на складе УК: Фильтр (F-1) — нужно 2, на складе доступно 1. Обратитесь в УК.']);
        $this->assertSame(0, StockMovement::query()->where('source_type', 'transfer')->count());
    }

    public function test_customer_has_no_stock_access(): void
    {
        $this->actingAs($this->users['customer'])->getJson('/api/requests/' . $this->request->uuid . '/stock')->assertForbidden();
        $this->actingAs($this->users['customer'])->getJson('/api/warehouse/documents?type=receipt')->assertForbidden();
    }
}
