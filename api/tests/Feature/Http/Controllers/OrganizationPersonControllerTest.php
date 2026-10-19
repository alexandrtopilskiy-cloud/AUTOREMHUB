<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для OrganizationPersonController.
 *
 * Покрывает:
 *   - POST   /api/catalogs/people       → 201 {record:{...}}
 *   - PATCH  /api/catalogs/people/{id}  → 200 {record:{...}}
 *   - DELETE /api/catalogs/people/{id}  → 200 {id, deleted:true}
 *
 * Бизнес-логика сервиса (дедупликация, restore, синхронизация VehicleContact,
 * аудит) покрывается через HTTP, а не юнит-тестами.
 */
final class OrganizationPersonControllerTest extends TestCase
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

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-25 09:00:00'));

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

    /* ─────────────────── store ─────────────────── */

    public function test_store_creates_contact_person_for_customer(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Иванов',
                'firstName' => 'Иван',
                'phone'     => '+7 (916) 111-22-33',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('record.lastName', 'Иванов')
            ->assertJsonPath('record.firstName', 'Иван')
            ->assertJsonPath('record.phoneRaw', '+79161112233')
            ->assertJsonPath('record.kind', 'contactPersons')
            ->assertJsonPath('record.personKind', 'contact');

        $this->assertDatabaseHas('organization_people', [
            'organization_id' => $this->customerOrg->id,
            'kind'            => 'contact',
            'phone'           => '+79161112233',
            'active'          => true,
        ]);
    }

    public function test_store_creates_driver_for_customer(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'driver',
                'lastName'  => 'Петров',
                'firstName' => 'Пётр',
                'phone'     => '+79161112244',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('record.kind', 'drivers')
            ->assertJsonPath('record.personKind', 'driver');
    }

    public function test_store_normalizes_phone(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Сидоров',
                'firstName' => 'Семён',
                'phone'     => '8 (916) 555-66-77',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('organization_people', [
            'phone' => '+79165556677',
        ]);
    }

    public function test_store_rejects_invalid_phone(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Иванов',
                'firstName' => 'Иван',
                'phone'     => '+7 (916) 12',
            ],
        ])->assertUnprocessable();
    }

    public function test_store_rejects_empty_record(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [])
            ->assertUnprocessable();
    }

    public function test_store_rejects_non_object_record(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => 'not-an-object',
        ])->assertUnprocessable();
    }

    public function test_store_rejects_extra_fields_in_body(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Иванов',
                'firstName' => 'Иван',
                'phone'     => '+79161112233',
            ],
            'extra' => 'nope',
        ])->assertUnprocessable();
    }

    public function test_store_rejects_duplicate_phone_with_422(): void
    {
        $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');

        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Другой',
                'firstName' => 'Человек',
                'phone'     => '+79161112233',
            ],
        ])->assertUnprocessable();
    }

    public function test_store_restores_inactive_person(): void
    {
        $existing = $this->makePerson(
            $this->customerOrg,
            'contact',
            'Старое',
            'Имя',
            '+79161112233',
            active: false,
        );

        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Новое',
                'firstName' => 'Имя',
                'phone'     => '+79161112233',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('record.id', $existing->uuid);

        $this->assertSame(1, OrganizationPerson::query()->count());
        $this->assertTrue($existing->refresh()->active);
        $this->assertDatabaseHas('catalog_change_events', [
            'record_uuid' => $existing->uuid,
            'action'      => 'restore',
        ]);
    }

    /* ─────────────────── update ─────────────────── */

    public function test_update_changes_fields(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/people/' . $person->uuid, [
            'patch' => [
                'lastName'  => 'Смирнов',
                'firstName' => 'Сергей',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('record.lastName', 'Смирнов')
            ->assertJsonPath('record.firstName', 'Сергей');
    }

    public function test_update_syncs_vehicle_contact(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');
        $vehicle = $this->makeVehicle($this->customerOrg);
        $contact = $this->makeVehicleContact($vehicle, $person, 'contact', '+79161112233');

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/people/' . $person->uuid, [
            'patch' => [
                'lastName' => 'Смирнов',
                'phone'    => '+79165556677',
            ],
        ])->assertOk();

        $contact->refresh();
        $this->assertSame('Смирнов', $contact->last_name);
        $this->assertSame('+79165556677', $contact->phone);
    }

    public function test_update_rejects_duplicate_phone_with_422(): void
    {
        $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');
        $target = $this->makePerson($this->customerOrg, 'contact', 'Петров', 'Пётр', '+79165556677');

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/people/' . $target->uuid, [
            'patch' => ['phone' => '+79161112233'],
        ])->assertUnprocessable();
    }

    public function test_update_returns_404_for_unknown_id(): void
    {
        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/people/' . Str::uuid(), [
            'patch' => ['lastName' => 'Смирнов'],
        ])->assertNotFound();
    }

    public function test_update_rejects_extra_fields_in_body(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/people/' . $person->uuid, [
            'patch' => ['lastName' => 'Смирнов'],
            'extra' => 'nope',
        ])->assertUnprocessable();
    }

    public function test_update_rejects_invalid_phone(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/people/' . $person->uuid, [
            'patch' => ['phone' => '123'],
        ])->assertUnprocessable();
    }

    /* ─────────────────── destroy ─────────────────── */

    public function test_destroy_soft_deletes_person(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');

        $this->actingAs($this->customer);
        $this->deleteJson('/api/catalogs/people/' . $person->uuid)
            ->assertOk()
            ->assertJsonPath('id', $person->uuid)
            ->assertJsonPath('deleted', true);

        $this->assertFalse($person->refresh()->active);
    }

    public function test_destroy_deactivates_vehicle_contacts(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');
        $vehicle = $this->makeVehicle($this->customerOrg);
        $contact = $this->makeVehicleContact($vehicle, $person, 'contact', '+79161112233');

        $this->actingAs($this->customer);
        $this->deleteJson('/api/catalogs/people/' . $person->uuid)
            ->assertOk();

        $this->assertFalse($contact->refresh()->active);
    }

    public function test_destroy_returns_404_for_unknown_id(): void
    {
        $this->actingAs($this->customer);
        $this->deleteJson('/api/catalogs/people/' . Str::uuid())
            ->assertNotFound();
    }

    public function test_destroy_rejects_body_fields_with_422(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');

        $this->actingAs($this->customer);
        $this->deleteJson('/api/catalogs/people/' . $person->uuid, [
            'extra' => 'nope',
        ])->assertUnprocessable();
    }

    /* ─────────────────── роли и auth ─────────────────── */

    public function test_store_forbidden_for_station(): void
    {
        $this->actingAs($this->station);
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Иванов',
                'firstName' => 'Иван',
                'phone'     => '+79161112233',
            ],
        ])->assertForbidden();
    }

    public function test_update_forbidden_for_station(): void
    {
        $person = $this->makePerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79161112233');

        $this->actingAs($this->station);
        $this->patchJson('/api/catalogs/people/' . $person->uuid, [
            'patch' => ['lastName' => 'Смирнов'],
        ])->assertForbidden();
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/catalogs/people', [
            'record' => [
                'kind'      => 'contact',
                'lastName'  => 'Иванов',
                'firstName' => 'Иван',
                'phone'     => '+79161112233',
            ],
        ])->assertUnauthorized();
    }

    /* ─────────────────── helpers ─────────────────── */

    private function makePerson(
        Organization $organization,
        string $kind,
        string $lastName,
        string $firstName,
        string $phone,
        bool $active = true,
    ): OrganizationPerson {
        return OrganizationPerson::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $organization->id,
            'kind'            => $kind,
            'last_name'       => $lastName,
            'first_name'      => $firstName,
            'phone'           => $phone,
            'active'          => $active,
        ]);
    }

    private function makeVehicle(Organization $organization): Vehicle
    {
        return Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $organization->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA' . str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
        ]);
    }

    private function makeVehicleContact(
        Vehicle $vehicle,
        OrganizationPerson $person,
        string $kind,
        string $phone,
    ): VehicleContact {
        return VehicleContact::query()->create([
            'vehicle_id'             => $vehicle->id,
            'organization_person_id' => $person->id,
            'kind'                   => $kind,
            'last_name'              => $person->last_name,
            'first_name'             => $person->first_name,
            'phone'                  => $phone,
            'active'                 => true,
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
