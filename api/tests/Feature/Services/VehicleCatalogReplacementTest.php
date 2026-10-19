<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\VehicleCatalogService;
use App\Services\VehicleReferenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class VehicleCatalogReplacementTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CreatesXlsxFiles;

    private User $manager;
    private Organization $customerOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::query()->create(['name' => 'Replacement test']);
        $managerOrganization = Organization::query()->create([
            'tenant_id' => $tenant->id, 'kind' => 'manager', 'name' => 'УК',
        ]);
        $this->customerOrganization = Organization::query()->create([
            'tenant_id' => $tenant->id, 'kind' => 'customer', 'name' => 'Заказчик',
        ]);
        $this->manager = User::query()->create([
            'organization_id' => $managerOrganization->id,
            'display_name' => 'Manager', 'login' => 'replacement-manager',
            'password' => 'password', 'role' => 'manager',
        ]);
    }

    public function test_volume_distinguishes_variants_and_is_returned_in_vehicle_catalog(): void
    {
        $service = app(VehicleReferenceService::class);
        $first = $service->create($this->manager, $this->referencePayload(1.5));
        $second = $service->create($this->manager, $this->referencePayload(2.0));
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(1.5, $first['engineVolumeLiters']);
        $this->assertSame($first['id'], $service->create($this->manager, $this->referencePayload(1.5))['id']);
        $options = $service->selectionOptions($this->manager, $first['makeId'], 'engineVolumeLiters', [
            'model' => 'Tiggo test', 'generation' => json_encode(['T1', 2020, 2026]),
            'engineType' => 'Бензин', 'engineIndex' => 'TEST', 'horsepower' => '150',
        ]);
        $this->assertCount(2, $options['options']);
        $selected = $service->selectionOptions($this->manager, $first['makeId'], 'driveType', [
            'model' => 'Tiggo test', 'generation' => json_encode(['T1', 2020, 2026]),
            'engineType' => 'Бензин', 'engineIndex' => 'TEST', 'horsepower' => '150',
            'engineVolumeLiters' => '1.5', 'transmission' => 'АКПП', 'driveType' => 'передний',
        ]);
        $this->assertSame($first['id'], $selected['variant']['id']);
    }

    public function test_three_rates_are_persisted_and_customer_cannot_change_them(): void
    {
        $reference = app(VehicleReferenceService::class)->create($this->manager, $this->referencePayload(1.5));
        $catalog = app(VehicleCatalogService::class);
        $result = $catalog->create($this->manager, [
            'make' => 'Chery', 'model' => 'Tiggo test', 'vehicleModelId' => $reference['id'],
            'organizationId' => $this->customerOrganization->uuid,
            'vin' => 'LVTDB21B5MD123456', 'plate' => 'А111АА78', 'year' => 2025,
            'laborHourRate' => 2000.0, 'laborHourRateOfficial' => 3000.0, 'laborHourRateOther' => 1500.0,
        ]);
        $id = $result['record']['id'];
        $vehicle = Vehicle::query()->where('uuid', $id)->firstOrFail();
        $this->assertSame('3000.00', $vehicle->laborRate('official'));
        $this->assertSame('2000.00', $vehicle->laborRate('general'));
        $this->assertSame('1500.00', $vehicle->laborRate('other'));
        $this->assertSame($id, $result['record']['id']);
        $this->assertSame(1.5, $result['record']['technicalReference']['engineVolumeLiters']);
        $catalog->update($this->manager, $id, ['laborHourRateOfficial' => 3500.0]);
        $this->assertSame('3500.00', $vehicle->refresh()->laborRate('official'));
        $customer = User::query()->create([
            'organization_id' => $this->customerOrganization->id,
            'display_name' => 'Customer', 'login' => 'replacement-customer',
            'password' => 'password', 'role' => 'customer',
        ]);
        $catalog->update($customer, $id, [
            'baselineServiceMileage' => 100, 'laborHourRateOfficial' => 1.0,
        ]);
        $this->assertSame('3500.00', $vehicle->refresh()->laborRate('official'));
        $this->assertSame(100, $vehicle->baseline_service_mileage);
    }

    public function test_new_vehicle_requires_a_catalog_variant(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(VehicleCatalogService::class)->create($this->manager, [
            'make' => 'Chery', 'model' => 'Unknown',
            'organizationId' => $this->customerOrganization->uuid,
            'vin' => 'LVTDB21B5MD123456',
        ]);
    }

    public function test_replacement_clears_test_vehicles_but_preserves_users_and_general_norms(): void
    {
        $reference = app(VehicleReferenceService::class)->create($this->manager, $this->referencePayload(1.5));
        $vehicle = Vehicle::query()->create([
            'tenant_id' => $this->customerOrganization->tenant_id,
            'organization_id' => $this->customerOrganization->id,
            'name' => 'Test', 'make' => 'Chery', 'model' => 'Tiggo test',
            'vehicle_model_id' => VehicleModel::query()->where('uuid', $reference['id'])->value('id'),
            'vin' => 'LVTDB21B5MD123456', 'plate' => '', 'plate_normalized' => '',
        ]);
        \App\Models\VehicleContact::query()->create([
            'vehicle_id' => $vehicle->id, 'last_name' => 'Петров',
            'first_name' => 'Пётр', 'phone' => '+79991234567',
        ]);
        $work = \App\Models\WorkType::query()->create([
            'tenant_id' => $this->customerOrganization->tenant_id, 'name' => 'Работа',
        ]);
        $general = \App\Models\TimeNorm::query()->create(['work_type_id' => $work->id, 'hours' => 1.0]);
        $specific = \App\Models\TimeNorm::query()->create([
            'work_type_id' => $work->id, 'vehicle_id' => $vehicle->id, 'hours' => 2.0,
        ]);
        $path = $this->replacementFile();
        $this->artisan('sto:replace-test-vehicle-catalog', ['file' => $path])->assertSuccessful();
        $this->assertSame(1, Vehicle::query()->count());
        $this->artisan('sto:replace-test-vehicle-catalog', ['file' => $path, '--apply' => true])->assertSuccessful();
        $this->assertSame(0, Vehicle::query()->count());
        $this->assertSame(0, \App\Models\VehicleContact::query()->count());
        $this->assertTrue($general->refresh()->active);
        $this->assertFalse($specific->refresh()->active);
        $this->assertTrue(User::query()->whereKey($this->manager->id)->exists());
        $this->assertTrue(\App\Models\WorkType::query()->whereKey($work->id)->exists());
        $this->assertSame(['Replacement'], VehicleModel::query()->where('active', true)->pluck('name')->all());
    }

    private function replacementFile(): string
    {
        return $this->createXlsxFile([
            ['Марка', 'Модель', 'Модельный год от', 'Модельный год до', 'Номер кузова (кузов)',
                'Индекс двигателя', 'Объем двигателя, л.', 'Мощность, л.с.', 'Вид трансмиссии', 'Тип привода', 'Тип двигателя'],
            ['Toyota', 'Replacement', '2020', '2026', 'TEST', 'TEST', '2.0', '150', 'АКПП', 'Передний', 'Бензин'],
        ], 'replacement-test-');
    }

    protected function tearDown(): void
    {
        $this->deleteXlsxFiles();
        parent::tearDown();
    }

    private function referencePayload(float $volume): array
    {
        return [
            'make' => 'Chery', 'model' => 'Tiggo test', 'bodyCode' => 'T1',
            'modelYearFrom' => 2020, 'modelYearTo' => 2026,
            'engineType' => 'Бензин', 'engineIndex' => 'TEST', 'horsepower' => 150.0,
            'engineVolumeLiters' => $volume, 'transmission' => 'АКПП', 'driveType' => 'передний',
        ];
    }
}
