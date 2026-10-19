<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Models\VehicleModel;
use App\Models\VehicleMake;
use App\Models\VehicleServiceRecord;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\VehicleService;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для VehicleService.
 *
 * Покрывает:
 *   - present: laborHourRate для manager/null для остальных,
 *     company для manager/customer, контакты/водители, permissions
 *   - contacts / drivers: фильтр active + сортировка
 *   - presentContact: форматирование
 *   - previousMileageDetails: 3 уровня fallback (заявка, сервис-рекорд, baseline)
 *   - statistics: history, maintenanceVisits
 */
final class VehicleServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private User $station;
    private Vehicle $vehicle;
    private ?Station $stationEntity = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
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

        $this->customer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager  = $this->makeUser($this->managerOrg, 'manager');
        $this->station  = $this->makeUser($this->stationOrg, 'station');

        $this->vehicle = $this->makeVehicle($this->customerOrg, [
            'name'             => 'Lada Vesta',
            'make'             => 'Lada',
            'model'            => 'Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'labor_hour_rate'  => 1000,
        ]);
    }

    /* ─────────────────── present ─────────────────── */

    public function test_present_manager_sees_labor_hour_rate(): void
    {
        $result = $this->service()->present($this->vehicle, $this->manager);

        $this->assertSame(1000.0, $result['laborHourRate']);
        $this->assertTrue($result['permissions']['canEditRate']);
    }

    public function test_present_customer_does_not_see_labor_hour_rate(): void
    {
        $result = $this->service()->present($this->vehicle, $this->customer);

        $this->assertNull($result['laborHourRate']);
        $this->assertFalse($result['permissions']['canEditRate']);
    }

    public function test_present_station_does_not_see_labor_hour_rate(): void
    {
        $result = $this->service()->present($this->vehicle, $this->station);

        $this->assertNull($result['laborHourRate']);
    }

    public function test_present_includes_vehicle_reference_data(): void
    {
        $make = VehicleMake::query()->create([
            'name'            => 'Lada',
            'normalized_name' => 'lada',
        ]);
        $model = VehicleModel::query()->create([
            'vehicle_make_id' => $make->id,
            'name'            => 'Vesta',
            'normalized_name' => 'vesta',
            'engine_index'    => '',
            'fingerprint'     => 'fp-' . Str::uuid(),
        ]);
        $this->vehicle->update(['vehicle_model_id' => $model->id]);

        $result = $this->service()->present($this->vehicle->refresh(), $this->manager);

        $this->assertSame('Lada', $result['make']);
        $this->assertSame('Vesta', $result['model']);
        $this->assertSame($model->uuid, $result['vehicleModelId']);
    }

    public function test_present_includes_contact_and_driver_names(): void
    {
        $this->makeContact($this->vehicle, 'contact', 'Иванов', 'Иван', '+79991111111');
        $this->makeContact($this->vehicle, 'driver', 'Петров', 'Пётр', '+79992222222');

        $result = $this->service()->present($this->vehicle->refresh(), $this->manager);

        $this->assertCount(1, $result['contacts']);
        $this->assertCount(1, $result['drivers']);
        $this->assertSame('Иванов Иван', $result['contactNames']);
        $this->assertSame('Петров Пётр', $result['driverNames']);
    }

    public function test_present_permissions_for_customer(): void
    {
        $result = $this->service()->present($this->vehicle, $this->customer);

        $this->assertTrue($result['permissions']['canEdit']);
        $this->assertTrue($result['permissions']['requiresApproval']);
        $this->assertTrue($result['permissions']['canDelete']);
    }

    /* ─────────────────── contacts / drivers ─────────────────── */

    public function test_contacts_returns_only_active_contacts_sorted(): void
    {
        $this->makeContact($this->vehicle, 'contact', 'Яковлев', 'Антон', '+79991111111');
        $this->makeContact($this->vehicle, 'contact', 'Абрамов', 'Борис', '+79992222222');
        $this->makeContact($this->vehicle, 'contact', 'Скрытый', 'Иван', '+79993333333', false);

        $result = $this->service()->contacts($this->vehicle->refresh());

        $this->assertCount(2, $result);
        $this->assertSame('Абрамов', $result[0]['lastName']);
        $this->assertSame('Яковлев', $result[1]['lastName']);
    }

    public function test_drivers_returns_only_active_drivers(): void
    {
        $this->makeContact($this->vehicle, 'driver', 'Петров', 'Пётр', '+79991111111');
        $this->makeContact($this->vehicle, 'driver', 'Скрытый', 'Иван', '+79992222222', false);

        $result = $this->service()->drivers($this->vehicle->refresh());

        $this->assertCount(1, $result);
        $this->assertSame('Петров', $result[0]['lastName']);
    }

    /* ─────────────────── presentContact ─────────────────── */

    public function test_present_contact_formats_phone(): void
    {
        $contact = $this->makeContact($this->vehicle, 'contact', 'Иванов', 'Иван', '+79991234567');

        $result = $this->service()->presentContact($contact);

        $this->assertSame('+7 (999) 123 45 67', $result['phone']);
        $this->assertSame('contact', $result['kind']);
        $this->assertSame('Иванов Иван', $result['fullName']);
    }

    /* ─────────────────── previousMileageDetails ─────────────────── */

    public function test_previous_mileage_from_latest_request(): void
    {
        $this->makeRequestWithMileage($this->vehicle, '15000');

        $result = $this->service()->previousMileageDetails($this->vehicle, $this->customer);

        $this->assertSame(15000, $result['mileage']);
        $this->assertNotNull($result['recordedAt']);
    }

    public function test_previous_mileage_from_request_excludes_given_id(): void
    {
        $request = $this->makeRequestWithMileage($this->vehicle, '15000');

        $result = $this->service()->previousMileageDetails(
            $this->vehicle,
            $this->customer,
            $request->id,
        );

        // Единственная заявка исключена → fallback на baseline.
        $this->assertNull($result['mileage']);
    }

    public function test_previous_mileage_from_service_record_when_no_requests(): void
    {
        $request = $this->makeRequestWithMileage($this->vehicle, '0');   // не учитывается
        VehicleServiceRecord::query()->create([
            'uuid'                 => (string) Str::uuid(),
            'vehicle_id'           => $this->vehicle->id,
            'repair_request_id'    => $request->id,
            'work_order_item_id'   => $this->makeWorkOrderItem($request)->id,
            'mileage'              => 20000,
            'performed_at'         => now()->subDay(),
            'is_maintenance'       => true,
        ]);
        // Обнулим пробег в заявке — она не пройдёт whereRaw regexp.
        $request->data = array_replace($request->data, ['mileage' => '']);
        $request->save();

        $result = $this->service()->previousMileageDetails($this->vehicle, $this->customer);

        $this->assertSame(20000, $result['mileage']);
    }

    public function test_previous_mileage_falls_back_to_baseline(): void
    {
        $this->vehicle->update([
            'baseline_service_mileage' => 5000,
            'baseline_service_at'      => now()->subMonth(),
        ]);

        $result = $this->service()->previousMileageDetails(
            $this->vehicle->refresh(),
            $this->customer,
        );

        $this->assertSame(5000, $result['mileage']);
        $this->assertNotNull($result['recordedAt']);
    }

    public function test_previous_mileage_returns_null_when_nothing_set(): void
    {
        $result = $this->service()->previousMileageDetails($this->vehicle, $this->customer);

        $this->assertNull($result['mileage']);
        $this->assertNull($result['recordedAt']);
    }

    /* ─────────────────── statistics ─────────────────── */

    public function test_statistics_returns_empty_for_new_vehicle(): void
    {
        $result = $this->service()->statistics($this->vehicle, $this->customer);

        $this->assertSame(0, $result['statistics']['completedWorks']);
        $this->assertSame(0, $result['statistics']['maintenanceVisits']);
        $this->assertNull($result['statistics']['maxRecordedMileage']);
        $this->assertSame([], $result['statistics']['history']);
        $this->assertSame($this->vehicle->uuid, $result['vehicle']['id']);
    }

    public function test_statistics_aggregates_history(): void
    {
        $request = $this->makeRequestWithMileage($this->vehicle, '15000');
        $item = $this->makeWorkOrderItem($request, 'Замена масла', 1.5);

        VehicleServiceRecord::query()->create([
            'uuid'                 => (string) Str::uuid(),
            'vehicle_id'           => $this->vehicle->id,
            'repair_request_id'    => $request->id,
            'work_order_item_id'   => $item->id,
            'mileage'              => 15000,
            'performed_at'         => now(),
            'is_maintenance'       => true,
        ]);

        $result = $this->service()->statistics($this->vehicle, $this->customer);

        $this->assertSame(1, $result['statistics']['completedWorks']);
        $this->assertSame(1, $result['statistics']['maintenanceVisits']);
        $this->assertSame(15000, $result['statistics']['maxRecordedMileage']);

        $row = $result['statistics']['history'][0];
        $this->assertSame('Замена масла', $row['work']);
        $this->assertSame(15000, $row['mileage']);
    }

    public function test_statistics_maintenance_visits_counts_unique_requests(): void
    {
        // Две разные заявки = два визита ТО.
        $request1 = $this->makeRequestWithMileage($this->vehicle, '15000');
        $request2 = $this->makeRequestWithMileage($this->vehicle, '20000');

        // Один WorkOrder на заявку (unique constraint).
        $order1 = $this->makeWorkOrder($request1);
        $order2 = $this->makeWorkOrder($request2);

        // Две записи в первой заявке, одна — во второй.
        $items = [
            [$this->makeWorkOrderItemFor($order1), $request1],
            [$this->makeWorkOrderItemFor($order1), $request1],
            [$this->makeWorkOrderItemFor($order2), $request2],
        ];

        foreach ($items as [$item, $request]) {
            VehicleServiceRecord::query()->create([
                'uuid'                 => (string) Str::uuid(),
                'vehicle_id'           => $this->vehicle->id,
                'repair_request_id'    => $request->id,
                'work_order_item_id'   => $item->id,
                'mileage'              => 15000,
                'performed_at'         => now(),
                'is_maintenance'       => true,
            ]);
        }

        $result = $this->service()->statistics($this->vehicle, $this->customer);

        // 3 записи истории, но только 2 уникальных визита ТО.
        $this->assertSame(3, $result['statistics']['completedWorks']);
        $this->assertSame(2, $result['statistics']['maintenanceVisits']);
    }

    /* ─────────────────── helpers ─────────────────── */

    private function service(): VehicleService
    {
        return app(VehicleService::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeVehicle(Organization $organization, array $overrides = []): Vehicle
    {
        return Vehicle::query()->create(array_merge([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $organization->id,
            'name'             => 'Lada',
            'vin'              => 'XTA0000000000' . random_int(1000, 9999),
            'plate'            => 'А' . random_int(100, 999) . 'АА77',
            'plate_normalized' => 'А' . random_int(100, 999) . 'АА77',
            'year'             => 2025,
        ], $overrides));
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
            'uuid'        => (string) Str::uuid(),
            'vehicle_id'  => $vehicle->id,
            'kind'        => $kind,
            'last_name'   => $lastName,
            'first_name'  => $firstName,
            'phone'       => $phone,
            'active'      => $active,
        ]);
    }

    private function makeRequestWithMileage(Vehicle $vehicle, string $mileage): RepairRequest
    {
        $uuid = (string) Str::uuid();

        $request = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'vehicle_id'               => $vehicle->id,
            'data'                     => array_replace(
                array_fill_keys(Workflow::TEXT_FIELDS, ''),
                ['mileage' => $mileage],
            ),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => 11,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
        ]);

        return $request;
    }

    private function makeWorkOrder(RepairRequest $request): WorkOrder
    {
        $station = Station::query()->firstOrCreate(
            ['organization_id' => $this->stationOrg->id],
            [
                'tenant_id' => $this->tenant->id,
                'name'      => 'СТО',
                'address'   => '',
                'active'    => true,
            ],
        );

        return WorkOrder::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'vehicle_id'        => $this->vehicle->id,
            'station_id'        => $station->id,
            'mileage'           => 15000,
            'completed_at'      => now(),
            'total'             => 100,
            'conclusion'        => 'ok',
            'estimate_version'  => 1,
        ]);
    }

    private function makeWorkOrderItemFor(
        WorkOrder $order,
        string $name = 'Работа',
        float $normHours = 1.0,
    ): WorkOrderItem {
        return WorkOrderItem::query()->create([
            'uuid'           => (string) Str::uuid(),
            'work_order_id'  => $order->id,
            'kind'           => 'work',
            'name'           => $name,
            'quantity'       => 1,
            'price'          => 100,
            'norm_hours'     => $normHours,
            'is_maintenance' => false,
        ]);
    }

    private function makeWorkOrderItem(
        RepairRequest $request,
        string $name = 'Работа',
        float $normHours = 1.0,
    ): WorkOrderItem {
        $order = $this->makeWorkOrder($request);

        return $this->makeWorkOrderItemFor($order, $name, $normHours);
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
