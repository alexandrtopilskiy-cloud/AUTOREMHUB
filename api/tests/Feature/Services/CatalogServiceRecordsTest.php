<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkType;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Characterization-тест для CatalogService::records().
 *
 * Проверяет, какие сущности видит каждая роль:
 *   - station  → пусто
 *   - customer → только свои vehicle + organizationPerson + pending approvals
 *   - manager  → всё в тенанте: stations, customers, справочники
 *
 * Пока в проекте нет enum'ов — работает со строками.
 * После этапа 0 — переписать на enum-версию.
 */
final class CatalogServiceRecordsTest extends TestCase
{
    use DatabaseTransactions;

    private const ROLES = ['customer', 'manager', 'station'];

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private User $station;
    private Vehicle $vehicle;
    private OrganizationPerson $person;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->vehicle = Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
            'labor_hour_rate'  => 1000,
        ]);

        $this->person = OrganizationPerson::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->customerOrg->id,
            'kind'            => 'contact',
            'last_name'       => 'Иванов',
            'first_name'      => 'Иван',
            'phone'           => '+79990000000',
        ]);

        WorkType::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Работа ТО',
            'active'    => true,
        ]);
        CatalogPart::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Фильтр',
            'article'   => 'F-1',
            'active'    => true,
        ]);
        Supplier::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Поставщик',
            'active'    => true,
        ]);
        ServiceReason::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Диагностика',
            'code'      => 'diagnostics',
            'active'    => true,
        ]);
        Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->stationOrg->id,
            'name'            => 'СТО-1',
            'address'         => 'Адрес',
        ]);
    }

    public function test_station_gets_shared_parts_and_suppliers(): void
    {
        $records = $this->service()->records($this->station);

        $this->assertEqualsCanonicalizing(['parts', 'suppliers'], array_unique(array_column($records, 'kind')));
    }

    public function test_customer_sees_only_own_vehicles_and_people(): void
    {
        $records = $this->service()->records($this->customer);

        $ids = array_column($records, 'id');

        $this->assertContains($this->vehicle->uuid, $ids);
        $this->assertContains($this->person->uuid, $ids);

        $kinds = array_unique(array_column($records, 'kind'));

        foreach ([
            'workTypes',
            'timeNorms',
            'parts',
            'reasons',
            'regulations',
            'suppliers',
            'stations',
            'customers',
            'vehicleApprovals',
        ] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $kinds,
                "Customer не должен видеть kind={$forbidden}",
            );
        }

        $this->assertCount(2, $records);
    }

    public function test_manager_sees_stations_customers_and_catalogs(): void
    {
        $records = $this->service()->records($this->manager);

        $kinds = array_unique(array_column($records, 'kind'));

        foreach ([
            'vehicles',
            'contactPersons',
            'stations',
            'customers',
            'managers',
            'users',
            'workTypes',
            'parts',
            'reasons',
            'suppliers',
        ] as $expected) {
            $this->assertContains(
                $expected,
                $kinds,
                "Manager должен видеть kind={$expected}",
            );
        }

        $customerIds = array_column(
            array_filter(
                $records,
                static fn (array $row): bool => $row['kind'] === 'customers',
            ),
            'id',
        );
        $this->assertContains($this->customerOrg->uuid, $customerIds);
    }

    /* ─────────────────── хелперы ─────────────────── */

    private function service(): CatalogService
    {
        return app(CatalogService::class);
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
