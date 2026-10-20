<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\RepairStage;
use App\Exceptions\ApiException;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\PartManufacturer;
use App\Models\RecommendationItem;
use App\Models\RepairRequest;
use App\Models\RequestPart;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TimeNorm;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleGeneration;
use App\Models\VehicleEngineVariant;
use App\Models\VehicleTransmissionType;
use App\Services\CatalogService;
use App\Services\RecommendationService;
use App\Services\RepairRequestService;
use App\Support\ApiPresenter;
use App\Support\TimeNormDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupplierAndRecommendationsTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;
    private User $customer;
    private User $station;
    private Vehicle $vehicle;
    private RepairRequest $request;
    private Supplier $supplier;
    private CatalogPart $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::query()->create(['name' => 'Recommendations test']);

        $roles = ['manager', 'customer', 'station'];
        foreach ($roles as $role) {
            $orgData = [
                'tenant_id' => $tenant->id,
                'kind' => $role,
                'name' => $role,
            ];
            $org = Organization::query()->create($orgData);
            $userData = [
                'organization_id' => $org->id,
                'display_name' => 'Иван ' . $role,
                'login' => $role . Str::random(8),
                'password' => bcrypt('test'),
                'role' => $role,
                'active' => true,
            ];
            $this->{$role} = User::query()->create($userData);
        }

        $vehicleData = [
            'tenant_id' => $tenant->id,
            'organization_id' => $this->customer->organization_id,
            'name' => 'Test Car',
            'make' => 'Test',
            'model' => 'Car',
            'vin' => 'TESTVIN1234567890',
            'plate' => 'A111AA78',
            'plate_normalized' => 'A111AA78',
            'labor_hour_rate' => 1000,
        ];
        $this->vehicle = Vehicle::query()->create($vehicleData);

        $stationData = [
            'tenant_id' => $tenant->id,
            'organization_id' => $this->station->organization_id,
            'name' => 'Station',
        ];
        $station = Station::query()->create($stationData);

        $requestData = [
            'public_id' => (string)Str::uuid(),
            'tenant_id' => $tenant->id,
            'customer_organization_id' => $this->customer->organization_id,
            'created_by' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'assigned_station_id' => $station->id,
            'stage' => RepairStage::ManagerRouting,
            'revision' => 0,
            'data' => [
                'mileage' => '1000',
                'laborRateType' => 'general'
            ],
            'works' => [],
            'history' => [],
        ];
        $this->request = RepairRequest::query()->create($requestData);

        $requestedPeriodData = [
            'repair_request_id' => $this->request->id,
            'stage' => 1,
            'entered_at' => now(),
            'actor_id' => $this->manager->id,
        ];
        RequestStatusPeriod::query()->create($requestedPeriodData);

        $supplierData = [
            'tenant_id' => $tenant->id,
            'name' => 'Supplier',
            'active' => true,
        ];
        $this->supplier = Supplier::query()->create($supplierData);

        $catalogData = [
            'tenant_id' => $tenant->id,
            'name' => 'Filter',
            'article' => 'F-1',
            'unit' => 'шт.',
            'manufacturer' => 'Test',
            'supplier_id' => $this->supplier->id,
            'sale_price' => 200,
            'active' => true,
        ];
        $this->catalog = CatalogPart::query()->create($catalogData);
    }

    private function work(): RequestWork
    {
        $data = [
            'repair_request_id' => $this->request->id,
            'name' => 'Двигатель - замена масла',
            'quantity' => 1,
            'price' => 1500,
            'hourly_rate' => 1000,
            'norm_hours' => 1.5,
            'line_amount' => 1500,
            'is_maintenance' => true,
        ];

        return RequestWork::query()->create($data);
    }

    private function part(): RequestPart
    {
        $data = [
            'repair_request_id' => $this->request->id,
            'catalog_part_id' => $this->catalog->id,
            'name' => 'Filter',
            'article' => 'F-1',
            'unit' => 'шт.',
            'supplier_id' => $this->supplier->id,
            'quantity' => 2,
            'price' => 250,
            'status' => 'proposed',
        ];
        return RequestPart::query()->create($data);
    }

    private function partPayload(): array
    {
        return [
            'id' => '',
            'catalogPartId' => $this->catalog->uuid,
            'name' => 'Filter',
            'article' => 'F-1',
            'unit' => 'шт.',
            'supplierId' => $this->supplier->uuid,
            'qty' => 2,
            'price' => 300,
        ];
    }

    private function supplierPayload(bool $individual = false): array
    {
        return [
            'legalForm' => $individual ? 'ip' : 'ooo',
            'name' => $individual ? 'ИП Иванов' : 'ООО Поставщик',
            'taxId' => $individual ? '500100732259' : '7707083893',
            'ogrn' => $individual ? '304500116000157' : '1027700132195',
            'kpp' => $individual ? '' : '773601001',
            'registrationDate' => '2020-01-15',
            'lastName' => $individual ? 'Иванов' : '',
            'firstName' => $individual ? 'Иван' : '',
            'directorName' => $individual ? '' : 'Иванов Иван Иванович',
            'legalAddress' => 'Москва',
            'actualAddress' => 'Москва',
            'postalAddress' => 'Москва',
            'bankName' => 'Банк',
            'bik' => '044525225',
            'settlementAccount' => '40702810900000002851',
            'correspondentAccount' => '30101810400000000225',
            'taxRegime' => 'ОСНО',
            'vatRate' => 'Без НДС',
            'contacts' => [[
                'lastName' => 'Иванов',
                'firstName' => 'Иван',
                'phone' => '+7 (999) 123 45 67',
                'email' => '',
            ]]
        ];
    }

    public function test_manager_creates_full_supplier_and_station_can_select_it(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/catalogs/suppliers', ['record' => $this->supplierPayload()])
            ->assertCreated()
            ->assertJsonPath('record.legalForm', 'ooo');

        $supplier = Supplier::query()
            ->where('tax_id', '7707083893')
            ->firstOrFail();

        $this->assertSame('044525225', $supplier->requisites['bik']);
        $this->assertCount(1, $supplier->contacts);

        $reference = app(CatalogService::class)->reference($this->station);

        $this->assertContains($supplier->uuid, array_column($reference['suppliers'], 'id'));
    }

    public function test_station_creates_individual_supplier_and_can_edit_own_supplier(): void
    {
        $service = app(CatalogService::class);
        $supplier = $service->create($this->station, 'suppliers', $this->supplierPayload(true));

        $this->assertTrue($supplier['permissions']['canEdit']);

        $saved = $service->update($this->station, $supplier['id'], ['name' => 'ИП Иванов Иван']);

        $this->assertSame('ИП Иванов Иван', $saved['name']);
        $this->assertContains($supplier['id'], array_column($service->records($this->station), 'id'));
    }

    public function test_individual_supplier_rejects_company_inn_and_bank_account_format(): void
    {
        $payload = $this->supplierPayload(true);
        $payload['taxId'] = '7707083893';
        $payload['settlementAccount'] = '123';

        $this->actingAs($this->station)
            ->postJson('/api/catalogs/suppliers', ['record' => $payload])
            ->assertUnprocessable();
        $this->assertSame(0, Supplier::query()->where('legal_form', 'ip')->count());
    }

    public function test_customer_cannot_create_suppliers_or_parts(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/catalogs/suppliers', ['record' => $this->supplierPayload()])
            ->assertForbidden();
        $this->postJson('/api/catalogs/parts', ['record' => []])->assertForbidden();
    }

    public function test_shared_part_creator_is_organization_and_cannot_be_reassigned(): void
    {
        $makerData = [
            'tenant_id' => $this->manager->tenantId(),
            'name' => 'BOSCH',
            'normalized_name' => 'bosch',
            'status' => 'approved',
            'active' => true,
        ];
        $maker = PartManufacturer::query()->create($makerData);

        $payload = [
            'name' => 'Фильтр',
            'article' => 'NEW-1',
            'manufacturerId' => $maker->uuid,
            'supplierId' => $this->supplier->uuid,
            'purchasePrice' => 100.5,
            'salePrice' => 200.0,
            'unit' => 'шт.',
            'active' => true,
        ];

        $service = app(CatalogService::class);
        $part = $service->create($this->station, 'parts', $payload);

        $this->assertSame($this->station->organization->name, $part['createdByOrganization']);
        $this->assertTrue($part['permissions']['canEdit']);

        $updated = $service->update($this->manager, $part['id'], ['salePrice' => 350.0]);

        $this->assertSame(350.0, $updated['salePrice']);
        $this->assertSame($part['createdByOrganization'], $updated['createdByOrganization']);
        $this->assertContains($part['id'], array_column($service->reference($this->station)['parts'], 'id'));
        $this->actingAs($this->manager)
            ->patchJson('/api/catalogs/' . $part['id'], ['patch' => ['createdByOrganization' => 'Подмена']])
            ->assertUnprocessable();
    }

    public function test_customer_document_and_recommendations_hide_supplier_and_purchase_price(): void
    {
        $part = $this->part();
        $part->update(['purchase_price' => 120.0]);
        $shown = app(ApiPresenter::class)->document($this->request, $this->customer);

        $this->assertArrayNotHasKey('supplierId', $shown['parts'][0]);
        $this->assertArrayNotHasKey('supplierName', $shown['parts'][0]);
        $this->assertArrayNotHasKey('purchasePrice', $shown['parts'][0]);

        $doc = app(RecommendationService::class)->defer($this->manager, $this->request->uuid, 0, ['workIds' => [], 'partIds' => [$part->uuid]])['recommendation'];
        $rows = app(RecommendationService::class)->forVehicle($this->customer, $this->vehicle->uuid);
        $this->assertSame($this->vehicle->plate, $rows[0]['plate']);
        $this->assertArrayNotHasKey('supplierId', $rows[0]['items'][0]['position']);
        $this->assertArrayNotHasKey('purchasePrice', $rows[0]['items'][0]['position']);
        $this->assertSame($doc['id'], $rows[0]['id']);
    }

    public function test_estimate_visibility_survives_return_from_customer_to_manager(): void
    {
        $presenter = app(ApiPresenter::class);
        $this->assertFalse($presenter->document($this->request, $this->customer)['permissions']['canViewRepairEstimate']);
        $this->request->update(['stage' => RepairStage::CustomerApproval]);
        $this->assertTrue($presenter->document($this->request, $this->customer)['permissions']['canViewRepairEstimate']);
        RequestStatusPeriod::query()->where('repair_request_id', $this->request->id)->update(['exited_at' => now()]);
        RequestStatusPeriod::query()->create(['repair_request_id' => $this->request->id, 'stage' => 3, 'entered_at' => now(), 'actor_id' => $this->customer->id]);
        $this->request->update(['stage' => RepairStage::ManagerRouting]);
        $this->assertTrue($presenter->document($this->request, $this->customer)['permissions']['canViewRepairEstimate']);
    }

    private function separateDocuments(): array
    {
        $service = app(RecommendationService::class);
        $work = $this->work();
        $part = $this->part();
        $part->update(['manufacturer_snapshot' => 'Original', 'purchase_price' => 120]);
        $a = $service->defer($this->manager, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []])['recommendation'];
        $b = $service->defer($this->manager, $this->request->uuid, 1, ['workIds' => [], 'partIds' => [$part->uuid]])['recommendation'];
        return [$a, $b];
    }

    public function test_restore_multiple_documents_in_one_request_revision_via_http(): void
    {
        $docs = $this->separateDocuments();
        $selection = array_map(fn ($doc) => ['id' => $doc['id'], 'revision' => $doc['revision'], 'itemIds' => array_column($doc['items'], 'id')], $docs);
        $this->actingAs($this->manager)->postJson('/api/recommendations/restore', ['documents' => $selection, 'requestId' => $this->request->uuid, 'requestRevision' => 2])->assertOk()->assertJsonPath('request.revision', 3)->assertJsonCount(1, 'request.parts')->assertJsonCount(1, 'request.works');
        $this->assertSame('120.00', $this->request->parts()->first()->purchase_price);
        $this->assertSame('Original', $this->request->parts()->first()->manufacturer_snapshot);
    }

    public function test_batch_conflict_rolls_back_all_recommendations(): void
    {
        $docs = $this->separateDocuments();
        $selection = array_map(fn ($doc) => ['id' => $doc['id'], 'revision' => 0, 'itemIds' => array_column($doc['items'], 'id')], $docs);
        $selection[1]['revision'] = 99;
        try {
            app(RecommendationService::class)->restoreMany($this->manager, $selection, $this->request->uuid, 2);
            $this->fail('Expected conflict');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame(2, $this->request->fresh()->revision);
        $this->assertSame(0, $this->request->parts()->count());
        $this->assertSame(0, RecommendationItem::query()->whereNotNull('moved_at')->count());
    }

    public function test_legacy_part_snapshot_with_extra_fields_restores_without_sql_error(): void
    {
        $part = $this->part();
        $service = app(RecommendationService::class);
        $doc = $service->defer($this->manager, $this->request->uuid, 0, ['workIds' => [], 'partIds' => [$part->uuid]])['recommendation'];
        $item = RecommendationItem::query()->where('uuid', $doc['items'][0]['id'])->firstOrFail();
        $item->update(['snapshot' => [...$item->snapshot, '_clientId' => 'old-ui-key', 'manufacturer' => 'Display only', 'supplierName' => 'Display only']]);
        $restored = $service->restore($this->manager, $doc['id'], 0, [$item->uuid], $this->request->uuid, 1);
        $this->assertSame(250.0, $restored['request']['parts'][0]['price']);
        $this->assertSame(2, $restored['request']['revision']);
    }

    public function test_part_snapshot_keeps_original_values_after_catalog_edit(): void
    {
        $service = app(RepairRequestService::class);
        $this->catalog->update(['manufacturer' => 'Original', 'purchase_price' => 100]);
        $service->update($this->manager, $this->request->uuid, 0, ['parts' => [$this->partPayload()]]);
        $this->catalog->update(['manufacturer' => 'Changed', 'purchase_price' => 500]);
        $shown = app(ApiPresenter::class)->document($this->request->fresh(), $this->manager);
        $this->assertSame('Original', $shown['parts'][0]['manufacturer']);
        $this->assertSame(100.0, $shown['parts'][0]['purchasePrice']);
        $this->assertSame(300.0, $shown['parts'][0]['price']);
    }

    public function test_norm_description_shows_only_actual_applicability(): void
    {
        $make = VehicleMake::query()->create(['name' => 'Toyota', 'normalized_name' => 'toyota']);
        $model = VehicleModel::query()->create(['vehicle_make_id' => $make->id, 'name' => 'Camry', 'normalized_name' => 'camry', 'fingerprint' => hash('sha256', (string) Str::uuid())]);
        $generation = VehicleGeneration::query()->create(['vehicle_model_id' => $model->id, 'name' => 'AXV70', 'normalized_name' => 'axv70']);
        $engine = VehicleEngineVariant::query()->create(['vehicle_model_id' => $model->id, 'vehicle_generation_id' => $generation->id, 'code' => '2AR-FE', 'fingerprint' => hash('sha256', (string) Str::uuid())]);
        $transmission = VehicleTransmissionType::query()->firstOrCreate(['code' => 'AT'], ['name' => 'АКПП']);
        $norm = new TimeNorm(['vehicle_make_id' => $make->id]);
        $this->assertSame('Применимость к Toyota', TimeNormDescription::label($norm));
        $norm = new TimeNorm(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'vehicle_generation_id' => $generation->id, 'vehicle_engine_variant_id' => $engine->id, 'vehicle_transmission_type_id' => $transmission->id]);
        $this->assertSame('Применимость к Toyota · Camry · AXV70 · 2AR-FE · АКПП', TimeNormDescription::label($norm));
        $this->assertSame('Применимость общая', TimeNormDescription::label(new TimeNorm()));
    }
    public function test_station_cannot_modify_supplier_owned_by_management(): void
    {
        $supplier = app(CatalogService::class)->create($this->manager, 'suppliers', $this->supplierPayload());
        $this->actingAs($this->station)->patchJson('/api/catalogs/' . $supplier['id'], ['patch' => ['name' => 'Подмена']])->assertForbidden();
        $this->assertSame('ООО Поставщик', Supplier::query()->where('uuid', $supplier['id'])->value('name'));
    }

    public function test_manual_catalog_accepts_new_body_variant_but_rejects_exact_duplicate(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/vehicle-reference/catalog', ['record' => ['make' => 'Toyota', 'model' => 'Camry', 'bodyCode' => 'AXV70']])->assertCreated();
        $this->postJson('/api/vehicle-reference/catalog', ['record' => ['make' => 'Toyota', 'model' => 'Camry', 'bodyCode' => 'AXV70A']])->assertCreated();
        $this->postJson('/api/vehicle-reference/catalog', ['record' => ['make' => 'Toyota', 'model' => 'Camry', 'bodyCode' => 'AXV70A']])->assertConflict();
    }

}
