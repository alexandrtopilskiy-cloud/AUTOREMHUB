<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Models\VehicleServiceRecord;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для VehicleController.
 *
 * Покрывает:
 *   - GET /api/vehicles                        (index: search, exclude, limit)
 *   - GET /api/vehicles/lookup                 (vin / plate)
 *   - GET /api/vehicles/{id}                   (show)
 *   - GET /api/vehicles/{id}/statistics        (history)
 *   - GET /api/vehicles/{id}/contacts
 *   - GET /api/vehicles/{id}/drivers
 */
final class VehicleControllerTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $otherCustomerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $otherCustomer;
    private User $manager;
    private User $station;
    private ?Station $stationRecord = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-25 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
        $this->otherCustomerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Другой заказчик»',
        ]);
        $this->managerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'manager',
            'name'      => 'УК',
        ]);
        $this->stationOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'station',
            'name'      => 'СТО',
        ]);

        $this->customer      = $this->makeUser($this->customerOrg, 'customer');
        $this->otherCustomer = $this->makeUser($this->otherCustomerOrg, 'customer');
        $this->manager       = $this->makeUser($this->managerOrg, 'manager');
        $this->station       = $this->makeUser($this->stationOrg, 'station');
    }

    /* ─────────────────── index ─────────────────── */

    public function test_index_returns_vehicles_of_user_organization(): void
    {
        $ours = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeVehicle($this->otherCustomerOrg, 'Kia Rio', 'XTA00000000000002');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'vehicles')
            ->assertJsonPath('vehicles.0.id', $ours->uuid);
    }

    public function test_index_excludes_inactive_vehicles(): void
    {
        $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeVehicle($this->customerOrg, 'Kia Rio', 'XTA00000000000002', active: false);

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'vehicles');
    }

    public function test_index_forbids_station_to_see_vehicles_without_requests(): void
    {
        $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->station);
        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(0, 'vehicles');
    }

    public function test_index_station_sees_vehicle_from_their_request(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeRequestForVehicle($vehicle, $this->customer, stage: 4);

        $this->actingAs($this->station);
        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'vehicles')
            ->assertJsonPath('vehicles.0.id', $vehicle->uuid);
    }

    public function test_index_search_by_vin_prefix(): void
    {
        $match = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeVehicle($this->customerOrg, 'Kia Rio', 'KNA00000000000002');

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/vehicles?q=XTA')
            ->assertOk()
            ->assertJsonCount(1, 'vehicles');

        $this->assertSame($match->uuid, $response->json('vehicles.0.id'));
    }

    public function test_index_search_by_plate_with_homoglyphs(): void
    {
        // VIN латиницей, госномер кириллицей.
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001', plate: 'А001АА77');

        $this->actingAs($this->customer);
        // Ищем латиницей A001AA77 — должно найтись через нормализацию.
        $this->getJson('/api/vehicles?q=A001AA77')
            ->assertOk()
            ->assertJsonCount(1, 'vehicles')
            ->assertJsonPath('vehicles.0.id', $vehicle->uuid);
    }

    public function test_index_respects_limit_50(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->makeVehicle(
                $this->customerOrg,
                'Car ' . $i,
                sprintf('XTA%014d', $i),
                plate: sprintf('А%03dАА77', $i),
            );
        }

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(50, 'vehicles');
    }

    /* ─────────────────── lookup ─────────────────── */

    public function test_lookup_finds_vehicle_by_vin(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/lookup?vin=XTA00000000000001')
            ->assertOk()
            ->assertJsonPath('vehicle.id', $vehicle->uuid);
    }

    public function test_lookup_finds_vehicle_by_plate(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001', plate: 'А001АА77');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/lookup?plate=А001АА77')
            ->assertOk()
            ->assertJsonPath('vehicle.id', $vehicle->uuid);
    }

    public function test_lookup_normalizes_plate_homoglyphs(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001', plate: 'А001АА77');

        $this->actingAs($this->customer);
        // Латиница вместо кириллицы — должно найти через plateKey().
        $this->getJson('/api/vehicles/lookup?plate=A001AA77')
            ->assertOk()
            ->assertJsonPath('vehicle.id', $vehicle->uuid);
    }

    public function test_lookup_returns_404_when_not_found(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/lookup?vin=XTA99999999999999')
            ->assertNotFound();
    }

    public function test_lookup_rejects_ambiguous_plate_with_422(): void
    {
        // Два ТС с одинаковым госномером — plateKey совпадёт.
        $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001', plate: 'А001АА77');
        $this->makeVehicle($this->customerOrg, 'Kia Rio', 'XTA00000000000002', plate: 'А001АА77');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/lookup?plate=А001АА77')
            ->assertUnprocessable();
    }

    public function test_lookup_requires_vin_or_plate(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/lookup')
            ->assertUnprocessable();
    }

    /* ─────────────────── show ─────────────────── */

    public function test_show_returns_vehicle_by_uuid(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/' . $vehicle->uuid)
            ->assertOk()
            ->assertJsonPath('vehicle.id', $vehicle->uuid)
            ->assertJsonPath('vehicle.vin', 'XTA00000000000001');
    }

    public function test_show_returns_404_for_unknown_uuid(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/' . Str::uuid())
            ->assertNotFound();
    }

    public function test_show_returns_404_for_non_uuid(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/not-a-uuid')
            ->assertNotFound();
    }

    /* ─────────────────── statistics ─────────────────── */

    public function test_statistics_returns_history(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $request = $this->makeRequestForVehicle($vehicle, $this->customer, stage: Workflow::STAGE_CLOSED);
        $this->makeServiceRecord($vehicle, $request, 'Замена масла', 15000, true);

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/' . $vehicle->uuid . '/statistics')
            ->assertOk()
            ->assertJsonPath('statistics.completedWorks', 1)
            ->assertJsonPath('statistics.maintenanceVisits', 1)
            ->assertJsonPath('statistics.maxRecordedMileage', 15000);
    }

    public function test_statistics_returns_404_for_unknown_vehicle(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/' . Str::uuid() . '/statistics')
            ->assertNotFound();
    }

    public function test_statistics_returns_empty_history_for_new_vehicle(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/' . $vehicle->uuid . '/statistics')
            ->assertOk()
            ->assertJsonPath('statistics.completedWorks', 0)
            ->assertJsonPath('statistics.maintenanceVisits', 0)
            ->assertJsonPath('statistics.history', []);
    }

    /* ─────────────────── contacts ─────────────────── */

    public function test_contacts_returns_active_contacts_sorted(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeContact($vehicle, 'contact', 'Иванов', 'Иван', '+79161112233');
        $this->makeContact($vehicle, 'contact', 'Абрамов', 'Пётр', '+79161112244');
        $this->makeContact($vehicle, 'contact', 'Скрытый', 'Сергей', '+79161112255', active: false);
        $this->makeContact($vehicle, 'driver', 'Водитель', 'Водитель', '+79161112266');

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/vehicles/' . $vehicle->uuid . '/contacts')
            ->assertOk()
            ->assertJsonCount(2, 'contacts');

        $names = collect($response->json('contacts'))->pluck('lastName')->all();
        $this->assertSame(['Абрамов', 'Иванов'], $names);
    }

    public function test_contacts_returns_404_for_unknown_vehicle(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/' . Str::uuid() . '/contacts')
            ->assertNotFound();
    }

    /* ─────────────────── drivers ─────────────────── */

    public function test_drivers_returns_active_drivers_sorted(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeContact($vehicle, 'driver', 'Яковлев', 'Ян', '+79161112233');
        $this->makeContact($vehicle, 'driver', 'Борисов', 'Борис', '+79161112244');
        $this->makeContact($vehicle, 'driver', 'Скрытый', 'Сергей', '+79161112255', active: false);
        $this->makeContact($vehicle, 'contact', 'Контакт', 'Контакт', '+79161112266');

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/vehicles/' . $vehicle->uuid . '/drivers')
            ->assertOk()
            ->assertJsonCount(2, 'drivers');

        $names = collect($response->json('drivers'))->pluck('lastName')->all();
        $this->assertSame(['Борисов', 'Яковлев'], $names);
    }

    public function test_drivers_returns_404_for_unknown_vehicle(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicles/' . Str::uuid() . '/drivers')
            ->assertNotFound();
    }

    /* ─────────────────── auth ─────────────────── */

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/vehicles')->assertUnauthorized();
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/vehicles/' . Str::uuid())->assertUnauthorized();
    }

    /* ─────────────────── helpers ─────────────────── */

    private function makeVehicle(
        Organization $organization,
        string $name,
        string $vin,
        string $plate = 'А000АА77',
        bool $active = true,
    ): Vehicle {
        return Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $organization->id,
            'name'             => $name,
            'vin'              => $vin,
            'plate'            => $plate,
            'plate_normalized' => Vehicle::plateKey($plate),
            'year'             => 2025,
            'active'           => $active,
        ]);
    }

    private function makeContact(
        Vehicle $vehicle,
        string $kind,
        string $lastName,
        string $firstName,
        string $phone,
        bool $active = true,
    ): VehicleContact {
        return VehicleContact::query()->create([
            'vehicle_id' => $vehicle->id,
            'kind'       => $kind,
            'last_name'  => $lastName,
            'first_name' => $firstName,
            'phone'      => $phone,
            'active'     => $active,
        ]);
    }

    private function makeRequestForVehicle(
        Vehicle $vehicle,
        User $author,
        int $stage,
    ): RepairRequest {
        $uuid = (string) Str::uuid();

        $request = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $vehicle->organization_id,
            'vehicle_id'               => $vehicle->id,
            'assigned_station_id'      => $this->stationRecordId(),
            'data'                     => array_replace(
                array_fill_keys(Workflow::TEXT_FIELDS, ''),
                ['mileage' => '15000'],
            ),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => $stage,
            'revision'                 => 1,
            'created_by'               => $author->id,
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'stage'             => $stage,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $author->id,
        ]);

        return $request;
    }

    private function stationRecordId(): int
    {
        if ($this->stationRecord === null) {
            $this->stationRecord = Station::query()->create([
                'tenant_id'       => $this->tenant->id,
                'organization_id' => $this->stationOrg->id,
                'name'            => 'Тестовая СТО',
                'address'         => 'Москва',
            ]);
        }

        return $this->stationRecord->id;
    }

    private function makeServiceRecord(
        Vehicle $vehicle,
        RepairRequest $request,
        string $workName,
        int $mileage,
        bool $isMaintenance,
    ): VehicleServiceRecord {
        $order = WorkOrder::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'vehicle_id'        => $vehicle->id,
            'station_id'        => $this->stationRecordId(),
            'mileage'           => $mileage,
            'completed_at'      => now(),
            'total'             => 1000,
            'conclusion'        => 'ok',
            'estimate_version'  => 1,
        ]);

        $item = WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'kind'          => 'work',
            'name'          => $workName,
            'quantity'      => 1,
            'price'         => 1000,
        ]);

        return VehicleServiceRecord::query()->create([
            'vehicle_id'         => $vehicle->id,
            'repair_request_id'  => $request->id,
            'work_order_item_id' => $item->id,
            'mileage'            => $mileage,
            'performed_at'       => now(),
            'is_maintenance'     => $isMaintenance,
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $role . '-' . Str::random(10),
            'display_name'    => ucfirst($role),
            'role'            => $role,
            'password'        => 'test-password-long',
            'active'          => true,
        ]);
    }
}