<?php
declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Services\OrganizationPersonService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для OrganizationPersonService.
 *
 * Покрывает:
 *   - records: customer / manager / station
 *   - create: успех, audit, restore soft-deleted, дубликат, validation
 *   - update: изменение, синхронизация VehicleContact, unique phone
 *   - delete: soft-delete + VehicleContact.active = false
 *   - права: station forbidden
 */
final class OrganizationPersonServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private User $station;

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
    }

    /* ─────────────────── records ─────────────────── */

    public function test_records_returns_empty_initially(): void
    {
        $this->assertSame([], $this->service()->records($this->customer));
    }

    public function test_records_customer_sees_only_own_organization(): void
    {
        $this->makePerson($this->customerOrg, 'contact', '+79991111111');
        $this->makePerson($this->managerOrg, 'contact', '+79992222222');

        $records = $this->service()->records($this->customer);

        $this->assertCount(1, $records);
        $this->assertSame($this->customerOrg->name, $records[0]['company']);
    }

    public function test_records_manager_sees_all_organizations_in_tenant(): void
    {
        $this->makePerson($this->customerOrg, 'contact', '+79991111111');
        $this->makePerson($this->customerOrg, 'driver', '+79992222222');
        $this->makePerson($this->managerOrg, 'contact', '+79993333333');

        $records = $this->service()->records($this->manager);

        $this->assertCount(3, $records);
    }

    public function test_records_station_sees_nothing(): void
    {
        $this->makePerson($this->customerOrg, 'contact', '+79991111111');

        $this->assertSame([], $this->service()->records($this->station));
    }

    /* ─────────────────── create ─────────────────── */

    public function test_create_creates_person_with_normalized_phone(): void
    {
        $result = $this->service()->create($this->customer, [
            'kind'       => 'contact',
            'lastName'   => 'Иванов',
            'firstName'  => 'Иван',
            'middleName' => 'Иванович',
            'phone'      => '+7 (999) 123-45-67',
        ]);

        $this->assertSame('contactPersons', $result['kind']);
        $this->assertSame('Иванов', $result['lastName']);
        $this->assertSame('+7 (999) 123 45 67', $result['phone']);
        $this->assertSame('+79991234567', $result['phoneRaw']);
        $this->assertTrue($result['active']);

        $this->assertSame(1, OrganizationPerson::query()->count());
    }

    public function test_create_creates_audit_event(): void
    {
        $this->service()->create($this->customer, [
            'kind'      => 'contact',
            'lastName'  => 'Иванов',
            'firstName' => 'Иван',
            'phone'     => '+79991234567',
        ]);

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'contactPersons')
            ->where('action', 'create')
            ->count());
    }

    public function test_create_driver_uses_drivers_catalog_kind(): void
    {
        $result = $this->service()->create($this->customer, [
            'kind'      => 'driver',
            'lastName'  => 'Петров',
            'firstName' => 'Пётр',
            'phone'     => '+79991234567',
        ]);

        $this->assertSame('drivers', $result['kind']);
    }

    public function test_create_restores_soft_deleted_person(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');
        $person->active = false;
        $person->save();

        $result = $this->service()->create($this->customer, [
            'kind'      => 'contact',
            'lastName'  => 'Иванов',
            'firstName' => 'Иван',
            'phone'     => '+79991234567',
        ]);

        $this->assertTrue($result['active']);
        $this->assertSame($person->uuid, $result['id']);
        $this->assertSame(1, OrganizationPerson::query()->count());

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('action', 'restore')
            ->count());
    }

    public function test_create_throws_on_duplicate_active_phone(): void
    {
        $this->makePerson($this->customerOrg, 'contact', '+79991234567');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Такой телефон уже есть в справочнике организации');

        $this->service()->create($this->customer, [
            'kind'      => 'contact',
            'lastName'  => 'Другой',
            'firstName' => 'Человек',
            'phone'     => '+79991234567',
        ]);
    }

    public function test_create_throws_on_invalid_phone(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Телефон должен быть в формате');

        $this->service()->create($this->customer, [
            'kind'      => 'contact',
            'lastName'  => 'Иванов',
            'firstName' => 'Иван',
            'phone'     => 'not-a-phone',
        ]);
    }

    public function test_create_forbidden_for_station(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->create($this->station, [
            'kind'      => 'contact',
            'lastName'  => 'Иванов',
            'firstName' => 'Иван',
            'phone'     => '+79991234567',
        ]);
    }

    /* ─────────────────── update ─────────────────── */

    public function test_update_changes_fields(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');

        $result = $this->service()->update($this->customer, $person->uuid, [
            'lastName'  => 'Сидоров',
            'firstName' => 'Семён',
        ]);

        $this->assertSame('Сидоров', $result['lastName']);
        $this->assertSame('Семён', $result['firstName']);
    }

    public function test_update_syncs_vehicle_contacts(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');
        $vehicle = $this->makeVehicle($this->customerOrg);

        VehicleContact::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'vehicle_id'               => $vehicle->id,
            'organization_person_id'   => $person->id,
            'kind'                     => 'contact',
            'last_name'                => 'Иванов',
            'first_name'               => 'Иван',
            'phone'                    => '+79991234567',
        ]);

        $this->service()->update($this->customer, $person->uuid, [
            'lastName'  => 'Сидоров',
            'firstName' => 'Семён',
        ]);

        $contact = VehicleContact::query()
        ->where('organization_person_id', $person->id)
            ->firstOrFail();

        $this->assertSame('Сидоров', $contact->last_name);
        $this->assertSame('Семён', $contact->first_name);
    }

    public function test_update_throws_on_duplicate_phone(): void
    {
        $this->makePerson($this->customerOrg, 'contact', '+79991234567');
        $other = $this->makePerson($this->customerOrg, 'contact', '+79997654321');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Такой телефон уже есть в справочнике организации');

        $this->service()->update($this->customer, $other->uuid, [
            'phone' => '+79991234567',
        ]);
    }

    public function test_update_forbidden_for_station(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');

        $this->expectException(ApiException::class);

        $this->service()->update($this->station, $person->uuid, [
            'lastName' => 'Сидоров',
        ]);
    }

    /* ─────────────────── delete ─────────────────── */

    public function test_delete_soft_deletes_person(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');

        $result = $this->service()->delete($this->customer, $person->uuid);

        $this->assertTrue($result['deleted']);
        $this->assertFalse($person->refresh()->active);
    }

    public function test_delete_deactivates_vehicle_contacts(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');
        $vehicle = $this->makeVehicle($this->customerOrg);

        VehicleContact::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'vehicle_id'               => $vehicle->id,
            'organization_person_id'   => $person->id,
            'kind'                     => 'contact',
            'last_name'                => 'Иванов',
            'first_name'               => 'Иван',
            'phone'                    => '+79991234567',
            'active'                   => true,
        ]);

        $this->service()->delete($this->customer, $person->uuid);

        $contact = VehicleContact::query()
        ->where('organization_person_id', $person->id)
            ->firstOrFail();

        $this->assertFalse($contact->active);
    }

    public function test_delete_creates_audit_event(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');

        $this->service()->delete($this->customer, $person->uuid);

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('action', 'delete')
            ->count());
    }

    public function test_delete_forbidden_for_station(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', '+79991234567');

        $this->expectException(ApiException::class);

        $this->service()->delete($this->station, $person->uuid);
    }

    /* ─────────────────── helpers ─────────────────── */

    private function service(): OrganizationPersonService
    {
        return app(OrganizationPersonService::class);
    }

    private function makePerson(
        Organization $organization,
        string $kind,
        string $phone,
    ): OrganizationPerson {
        return OrganizationPerson::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $organization->id,
            'kind'            => $kind,
            'last_name'       => 'Иванов',
            'first_name'      => 'Иван',
            'phone'           => $phone,
            'active'          => true,
        ]);
    }

    private function makeVehicle(Organization $organization): Vehicle
    {
        return Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $organization->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA0000000000' . random_int(1000, 9999),
            'plate'            => 'А' . random_int(100, 999) . 'АА77',
            'plate_normalized' => 'А' . random_int(100, 999) . 'АА77',
            'year'             => 2025,
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
