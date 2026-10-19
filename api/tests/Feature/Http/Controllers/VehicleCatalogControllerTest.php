<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\RequestNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleChangeRequest;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\ReferenceSeeder;
use App\Services\VehicleReferenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для VehicleCatalogController.
 *
 * Покрывает:
 *   - POST   /api/catalogs/vehicles                          (store)
 *   - PATCH  /api/catalogs/vehicles/{id}                     (update)
 *   - DELETE /api/catalogs/vehicles/{id}                     (destroy)
 *   - POST   /api/catalogs/vehicle-approvals/{id}/decision   (review)
 *
 * Логика: manager применяет сразу, customer создаёт VehicleChangeRequest
 * со статусом pending; manager затем одобряет или отклоняет.
 */
final class VehicleCatalogControllerTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private User $station;
    private VehicleModel $vehicleModel;

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

        app(ReferenceSeeder::class)->seed($this->tenant->id);

        // С 0.37.x карточка ТС создаётся только с выбранным техническим
        // вариантом внутреннего справочника (vehicleModelId обязателен).
        $make = VehicleMake::query()->create([
            'name'            => 'Lada',
            'normalized_name' => VehicleReferenceService::normalizeName('Lada'),
        ]);
        $this->vehicleModel = VehicleModel::query()->create([
            'vehicle_make_id' => $make->id,
            'name'            => 'Vesta',
            'normalized_name' => VehicleReferenceService::normalizeName('Vesta'),
            'engine_index'    => '',
            'fingerprint'     => 'fp-' . Str::uuid(),
        ]);
    }

    /* ─────────────────── store ─────────────────── */

    public function test_store_creates_vehicle_for_manager(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/vehicles', [
            'record' => $this->vehiclePayload(),
        ])
            ->assertCreated()
            ->assertJsonPath('pendingApproval', false)
            ->assertJsonPath('record.vin', 'XTA00000000000001');

        $this->assertDatabaseHas('vehicles', [
            'tenant_id' => $this->tenant->id,
            'vin'       => 'XTA00000000000001',
        ]);
        $this->assertSame(0, VehicleChangeRequest::query()->count());
    }

    public function test_store_creates_change_request_for_customer(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/vehicles', [
            'record' => $this->vehiclePayload(),
        ])
            ->assertCreated()
            ->assertJsonPath('pendingApproval', true)
            ->assertJsonStructure(['record' => ['changeRequestId'], 'changeRequest' => ['id', 'action', 'status']]);

        $this->assertSame(1, VehicleChangeRequest::query()->count());
        $this->assertSame(
            'pending',
            VehicleChangeRequest::query()->firstOrFail()->status,
        );
    }

    public function test_store_notifies_managers_on_customer_request(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/vehicles', [
            'record' => $this->vehiclePayload(),
        ])->assertCreated();

        $this->assertDatabaseHas('request_notifications', [
            'user_id' => $this->manager->id,
            'kind'    => 'vehicle_catalog_approval',
        ]);
    }

    public function test_store_rejects_duplicate_vin(): void
    {
        Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Existing',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
        ]);

        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/vehicles', [
            'record' => $this->vehiclePayload(),
        ])->assertUnprocessable();
    }

    public function test_store_rejects_invalid_vin(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/vehicles', [
            'record' => array_merge($this->vehiclePayload(), ['vin' => 'ABC']),
        ])->assertUnprocessable();
    }

    public function test_store_forbidden_for_station(): void
    {
        $this->actingAs($this->station);
        $this->postJson('/api/catalogs/vehicles', [
            'record' => $this->vehiclePayload(),
        ])->assertForbidden();
    }

    /* ─────────────────── update ─────────────────── */

    public function test_update_changes_vehicle_for_manager(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->manager);
        $this->patchJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'patch' => [
                'laborHourRate' => 2500,
                'year'          => 2024,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('pendingApproval', false);

        $this->assertSame('2500.00', $vehicle->refresh()->labor_hour_rate);
        $this->assertSame(2024, $vehicle->year);
    }

    public function test_update_creates_change_request_for_customer(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'patch' => [
                'make'  => 'Lada',
                'model' => 'Vesta NG',
                'vin'   => $vehicle->vin,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('pendingApproval', true);

        $this->assertSame(1, VehicleChangeRequest::query()->count());
        // Изменения не применены
        $this->assertSame('Vesta', $vehicle->refresh()->model);
    }

    public function test_update_applies_direct_fields_for_customer(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'patch' => [
                'baselineServiceMileage' => 50000,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('pendingApproval', false);

        // Пробег применяется сразу
        $this->assertSame(50000, $vehicle->refresh()->baseline_service_mileage);
        $this->assertSame(0, VehicleChangeRequest::query()->count());
    }

    public function test_update_returns_404_for_unknown_vehicle(): void
    {
        $this->actingAs($this->manager);
        $this->patchJson('/api/catalogs/vehicles/' . Str::uuid(), [
            'patch' => ['year' => 2024],
        ])->assertNotFound();
    }

    public function test_update_rejects_extra_fields_in_body(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->manager);
        $this->patchJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'patch' => ['year' => 2024],
            'extra' => 'nope',
        ])->assertUnprocessable();
    }

    /* ─────────────────── destroy ─────────────────── */

    public function test_destroy_deactivates_vehicle_for_manager(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->manager);
        $this->deleteJson('/api/catalogs/vehicles/' . $vehicle->uuid)
            ->assertOk()
            ->assertJsonPath('pendingApproval', false);

        $this->assertFalse($vehicle->refresh()->active);
    }

    public function test_destroy_creates_change_request_for_customer(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->customer);
        $this->deleteJson('/api/catalogs/vehicles/' . $vehicle->uuid)
            ->assertOk()
            ->assertJsonPath('pendingApproval', true);

        $change = VehicleChangeRequest::query()->firstOrFail();
        $this->assertSame('delete', $change->action);
        $this->assertSame('pending', $change->status);
        $this->assertTrue($vehicle->refresh()->active);
    }

    public function test_destroy_returns_404_for_unknown_vehicle(): void
    {
        $this->actingAs($this->manager);
        $this->deleteJson('/api/catalogs/vehicles/' . Str::uuid())
            ->assertNotFound();
    }

    public function test_destroy_rejects_body_fields_with_422(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->manager);
        $this->deleteJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'extra' => 'nope',
        ])->assertUnprocessable();
    }

    /* ─────────────────── review ─────────────────── */

    public function test_review_approves_pending_change(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'patch' => [
                'make'  => 'Lada',
                'model' => 'Vesta NG',
                'vin'   => $vehicle->vin,
            ],
        ])->assertOk();

        $change = VehicleChangeRequest::query()->firstOrFail();

        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/vehicle-approvals/' . $change->uuid . '/decision', [
            'decision' => 'approved',
            'comment'  => 'Согласовано',
        ])
            ->assertOk()
            ->assertJsonPath('record.status', 'approved');

        $this->assertSame('Vesta NG', $vehicle->refresh()->model);
        $this->assertSame('approved', $change->refresh()->status);
        $this->assertSame($this->manager->id, $change->reviewed_by);
    }

    public function test_review_rejects_pending_change(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'patch' => [
                'make'  => 'Lada',
                'model' => 'Vesta NG',
                'vin'   => $vehicle->vin,
            ],
        ])->assertOk();

        $change = VehicleChangeRequest::query()->firstOrFail();

        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/vehicle-approvals/' . $change->uuid . '/decision', [
            'decision' => 'rejected',
            'comment'  => 'Не согласовано',
        ])
            ->assertOk()
            ->assertJsonPath('record.status', 'rejected');

        // Изменение не применено
        $this->assertSame('Vesta', $vehicle->refresh()->model);
    }

    public function test_review_forbidden_for_customer(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/vehicles/' . $vehicle->uuid, [
            'patch' => ['baselineServiceMileage' => 50000],
        ])->assertOk();

        // customer не может review
        $change = VehicleChangeRequest::query()->first();
        $this->assertNull($change);

        // создаём change явно
        $change = VehicleChangeRequest::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->customerOrg->id,
            'vehicle_id'      => $vehicle->id,
            'requested_by'    => $this->customer->id,
            'action'          => 'update',
            'status'          => 'pending',
            'payload'         => ['year' => 2024],
        ]);

        $this->postJson('/api/catalogs/vehicle-approvals/' . $change->uuid . '/decision', [
            'decision' => 'approved',
            'comment'  => 'ok',
        ])->assertForbidden();
    }

    public function test_review_returns_404_for_unknown_change(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/vehicle-approvals/' . Str::uuid() . '/decision', [
            'decision' => 'approved',
            'comment'  => 'ok',
        ])->assertNotFound();
    }

    public function test_review_rejects_already_reviewed_change(): void
    {
        $vehicle = $this->makeVehicle();
        $change = VehicleChangeRequest::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->customerOrg->id,
            'vehicle_id'      => $vehicle->id,
            'requested_by'    => $this->customer->id,
            'action'          => 'update',
            'status'          => 'approved',
            'payload'         => ['year' => 2024],
        ]);

        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/vehicle-approvals/' . $change->uuid . '/decision', [
            'decision' => 'rejected',
            'comment'  => 'ok',
        ])->assertUnprocessable();
    }

    /* ─────────────────── helpers ─────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function vehiclePayload(): array
    {
        return [
            'make'             => 'Lada',
            'model'            => 'Vesta',
            'vehicleModelId'   => $this->vehicleModel->uuid,
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'year'             => 2025,
            'organizationId'   => $this->customerOrg->uuid,
            'contactPersonIds' => [],
            'driverPersonIds'  => [],
        ];
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Lada Vesta',
            'make'             => 'Lada',
            'model'            => 'Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
            'active'           => true,
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