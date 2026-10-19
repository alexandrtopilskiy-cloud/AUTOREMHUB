<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleEngineVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\VehicleReferenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для VehicleReferenceController.
 *
 * Покрывает:
 *   - GET  /api/vehicle-reference/makes   (search, limit, defaults)
 *   - GET  /api/vehicle-reference/models  (makeId, search, limit)
 *   - POST /api/vehicle-reference/models  (record, 201, rights)
 */
final class VehicleReferenceControllerTest extends TestCase
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

    /* ─────────────────── GET makes ─────────────────── */

    public function test_makes_returns_all_with_default_limit(): void
    {
        $this->makeMake('Audi');
        $this->makeMake('BMW');

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/vehicle-reference/makes');

        $response->assertOk()
            ->assertJsonCount(2, 'makes')
            ->assertJsonPath('makes.0.name', 'Audi')
            ->assertJsonPath('makes.1.name', 'BMW');
    }

    public function test_makes_filters_by_prefix_search(): void
    {
        $this->makeMake('Audi');
        $this->makeMake('Aston Martin');
        $this->makeMake('BMW');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/makes?search=Au')
            ->assertOk()
            ->assertJsonCount(1, 'makes')
            ->assertJsonPath('makes.0.name', 'Audi');
    }

    public function test_makes_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeMake("Make{$i}");
        }

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/makes?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'makes');
    }

    public function test_makes_rejects_limit_over_100(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/makes?limit=999')
            ->assertStatus(422);
    }

    public function test_makes_forbidden_for_station(): void
    {
        $this->actingAs($this->station);
        $this->getJson('/api/vehicle-reference/makes')
            ->assertForbidden();
    }

    /* ─────────────────── GET models ─────────────────── */

    public function test_models_returns_models_of_make(): void
    {
        $make = $this->makeMake('Audi');
        $this->makeModel($make, 'A4');
        $this->makeModel($make, 'A6');

        $other = $this->makeMake('BMW');
        $this->makeModel($other, 'X5');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/models?makeId=' . $make->uuid)
            ->assertOk()
            ->assertJsonCount(2, 'models')
            ->assertJsonPath('models.0.model', 'A4');
    }

    public function test_models_filters_by_search(): void
    {
        $make = $this->makeMake('Audi');
        $this->makeModel($make, 'A4');
        $this->makeModel($make, 'A6');
        $this->makeModel($make, 'Q7');

        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/models?makeId=' . $make->uuid . '&search=A')
            ->assertOk()
            ->assertJsonCount(2, 'models');
    }

    public function test_models_requires_make_id(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/models')
            ->assertStatus(422);
    }

    public function test_models_rejects_invalid_uuid(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/models?makeId=not-a-uuid')
            ->assertStatus(422);
    }

    public function test_models_404_for_unknown_make(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/vehicle-reference/models?makeId=' . Str::uuid())
            ->assertNotFound();
    }

    public function test_detailed_vehicle_applicability_references_are_available(): void
    {
        $make = $this->makeMake('Audi');
        $model = $this->makeModel($make, 'A4');
        $generation = VehicleGeneration::query()->create([
            'vehicle_model_id' => $model->id,
            'name'             => 'B9',
            'normalized_name'  => 'b9',
            'year_from'        => 2015,
            'active'           => true,
        ]);
        $engine = VehicleEngineVariant::query()->create([
            'vehicle_model_id'      => $model->id,
            'vehicle_generation_id' => $generation->id,
            'code'                  => 'CZEA',
            'fingerprint'           => hash('sha256', Str::random(20)),
            'active'                => true,
        ]);

        $this->actingAs($this->manager);

        $this->getJson(
            '/api/vehicle-reference/generations?modelId=' . $model->uuid,
        )
            ->assertOk()
            ->assertJsonPath('generations.0.id', $generation->uuid)
            ->assertJsonPath('generations.0.name', 'B9');

        $this->getJson(
            '/api/vehicle-reference/engines?modelId=' . $model->uuid
            . '&generationId=' . $generation->uuid,
        )
            ->assertOk()
            ->assertJsonPath('engines.0.id', $engine->uuid)
            ->assertJsonPath('engines.0.name', 'CZEA');

        $this->getJson('/api/vehicle-reference/transmissions')
            ->assertOk()
            ->assertJsonStructure(['transmissions' => [['id', 'code', 'name']]]);

        $this->getJson('/api/vehicle-reference/drives')
            ->assertOk()
            ->assertJsonStructure(['drives' => [['id', 'code', 'name']]]);
    }

    /* ─────────────────── POST models ─────────────────── */

    public function test_store_creates_make_and_model(): void
    {
        $this->actingAs($this->customer);
        $response = $this->postJson('/api/vehicle-reference/models', [
            'record' => [
                'make'        => 'Audi',
                'model'       => 'A4',
                'engineIndex' => 'B9',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('model.make', 'Audi')
            ->assertJsonPath('model.model', 'A4');

        $this->assertSame(1, VehicleMake::query()->count());
        $this->assertSame(1, VehicleModel::query()->count());
    }

    public function test_store_is_idempotent(): void
    {
        $payload = [
            'record' => [
                'make'        => 'Audi',
                'model'       => 'A4',
                'engineIndex' => 'B9',
            ],
        ];

        $this->actingAs($this->customer);
        $first = $this->postJson('/api/vehicle-reference/models', $payload)
            ->assertCreated()->json('model');

        $second = $this->postJson('/api/vehicle-reference/models', $payload)
            ->assertCreated()->json('model');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, VehicleModel::query()->count());
    }

    public function test_store_creates_audit_event(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/vehicle-reference/models', [
            'record' => ['make' => 'Audi', 'model' => 'A4', 'engineIndex' => 'B9'],
        ])->assertCreated();

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'vehicleModels')
            ->where('action', 'create')
            ->count());
    }

    public function test_store_rejects_missing_record(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/vehicle-reference/models', [])
            ->assertStatus(422);
    }

    public function test_store_rejects_non_object_record(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/vehicle-reference/models', [
            'record' => 'not-an-object',
        ])->assertStatus(422);
    }

    public function test_store_forbidden_for_station(): void
    {
        $this->actingAs($this->station);
        $this->postJson('/api/vehicle-reference/models', [
            'record' => ['make' => 'Audi', 'model' => 'A4'],
        ])->assertForbidden();
    }

    public function test_store_validates_required_make(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/vehicle-reference/models', [
            'record' => ['make' => '', 'model' => 'A4'],
        ])->assertStatus(422);
    }

    public function test_manager_catalog_is_paginated_and_filterable(): void
    {
        $audi = $this->makeMake('Audi');
        $a4 = $this->makeModel($audi, 'A4');
        $a4->forceFill(['body_code' => 'B9'])->save();
        $this->makeModel($audi, 'A6');
        $bmw = $this->makeMake('BMW');
        $this->makeModel($bmw, 'X5');

        $this->actingAs($this->manager);
        $this->getJson('/api/vehicle-reference/catalog?model=A4&perPage=25')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('perPage', 25)
            ->assertJsonPath('records.0.make', 'Audi')
            ->assertJsonPath('records.0.model', 'A4')
            ->assertJsonPath('records.0.bodyCode', 'B9');
    }

    public function test_internal_catalog_is_visible_only_to_manager(): void
    {
        $this->actingAs($this->customer);

        $this->getJson('/api/vehicle-reference/catalog')->assertForbidden();
    }

    /* ─────────────────── helpers ─────────────────── */

    private function makeMake(string $name): VehicleMake
    {
        return VehicleMake::query()->create([
            'name'            => $name,
            'normalized_name' => VehicleReferenceService::normalizeName($name),
        ]);
    }

    private function makeModel(VehicleMake $make, string $name): VehicleModel
    {
        $attributes = [
            'vehicle_make_id' => $make->id,
            'name'            => $name,
            'normalized_name' => VehicleReferenceService::normalizeName($name),
            'engine_index'    => '',
        ];

        return VehicleModel::query()->create(array_merge($attributes, [
            'fingerprint' => VehicleReferenceService::fingerprint($make->name, $attributes),
        ]));
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
