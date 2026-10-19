<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\VehicleReferenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Feature-тест для VehicleReferenceService.
 *
 * Покрывает:
 *   - makes: пустой поиск, поиск по фрагментам, роль reader
 *   - models: по makeId, поиск по фрагментам, роль reader
 *   - create: создание, идемпотентность (fingerprint), валидация, права
 *   - static helpers: cleanName, normalizeName, fingerprint
 */
final class VehicleReferenceServiceTest extends TestCase
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

    /* ─────────────────── makes ─────────────────── */

    public function test_makes_returns_empty_when_no_data(): void
    {
        $result = $this->service()->makes($this->customer, '', 10);

        $this->assertSame([], $result);
    }

    public function test_makes_returns_all_ordered_by_normalized_name(): void
    {
        $this->makeMake('Volvo');
        $this->makeMake('Audi');
        $this->makeMake('BMW');

        $result = $this->service()->makes($this->customer, '', 10);

        $this->assertCount(3, $result);
        $this->assertSame('Audi', $result[0]['name']);
        $this->assertSame('BMW', $result[1]['name']);
        $this->assertSame('Volvo', $result[2]['name']);
    }

    public function test_makes_filters_by_prefix(): void
    {
        $this->makeMake('Audi');
        $this->makeMake('BMW');
        $this->makeMake('Aston Martin');

        $result = $this->service()->makes($this->customer, 'Au', 10);

        $this->assertCount(1, $result);
        $this->assertSame('Audi', $result[0]['name']);
    }

    public function test_makes_search_is_case_insensitive(): void
    {
        $this->makeMake('Audi');

        $result = $this->service()->makes($this->customer, 'AUDI', 10);

        $this->assertCount(1, $result);
    }

    public function test_makes_search_ignores_extra_spaces(): void
    {
        $this->makeMake('Aston Martin');

        $result = $this->service()->makes($this->customer, '  Aston   Ma  ', 10);

        $this->assertCount(1, $result);
    }

    public function test_reference_search_matches_internal_fragments_and_literal_symbols(): void
    {
        $make = $this->makeMake('Aston Martin');
        $this->makeMake('Audi');
        $this->assertCount(1, $this->service()->makes($this->customer, 'tin sto', 10));
        $this->assertCount(0, $this->service()->makes($this->customer, 'tin audi', 10));
        $this->assertCount(0, $this->service()->makes($this->customer, '%', 10));
        $this->makeModel($make, 'Camry Hybrid');
        $this->assertCount(1, $this->service()->models($this->customer, $make->uuid, 'brid amr', 10));
    }

    public function test_makes_limited_by_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeMake("Make{$i}");
        }

        $result = $this->service()->makes($this->customer, '', 3);

        $this->assertCount(3, $result);
    }

    public function test_makes_forbidden_for_station(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->makes($this->station, '', 10);
    }

    /* ─────────────────── models ─────────────────── */

    public function test_models_returns_models_of_make(): void
    {
        $make = $this->makeMake('Audi');
        $this->makeModel($make, 'A4');
        $this->makeModel($make, 'A6');

        $other = $this->makeMake('BMW');
        $this->makeModel($other, 'X5');

        $result = $this->service()->models($this->customer, $make->uuid, '', 10);

        $this->assertCount(2, $result);
        $this->assertSame('A4', $result[0]['model']);
        $this->assertSame('A6', $result[1]['model']);
    }

    public function test_models_filters_by_prefix(): void
    {
        $make = $this->makeMake('Audi');
        $this->makeModel($make, 'A4');
        $this->makeModel($make, 'A6');
        $this->makeModel($make, 'Q7');

        $result = $this->service()->models($this->customer, $make->uuid, 'A', 10);

        $this->assertCount(2, $result);
    }

    public function test_unique_model_names_are_grouped_before_result_limit(): void
    {
        $make = $this->makeMake('Toyota');
        $first = $this->makeModel($make, 'Camry');
        $other = $first->replicate(['uuid']);
        $other->engine_index = '2AR-FE';
        $other->fingerprint = VehicleReferenceService::fingerprint($make->name, $other->getAttributes());
        $other->save();
        $this->makeModel($make, 'Corolla');

        $result = $this->service()->models($this->manager, $make->uuid, '', 2, true);

        $this->assertSame(['Camry', 'Corolla'], array_column($result, 'model'));
        $this->assertCount(3, $this->service()->models($this->manager, $make->uuid, '', 10));
    }

    public function test_generations_and_engines_include_all_variants_of_the_model(): void
    {
        $make = $this->makeMake('Toyota');
        $first = $this->makeModel($make, 'Camry');
        $second = $first->replicate(['uuid']);
        $second->engine_index = '2AR-FE';
        $second->fingerprint = VehicleReferenceService::fingerprint($make->name, $second->getAttributes());
        $second->save();
        $generations = [];
        foreach ([$first, $second] as $index => $model) {
            $generation = \App\Models\VehicleGeneration::query()->create([
                'vehicle_model_id' => $model->id,
                'name' => $index === 0 ? 'MCV10' : 'XV50',
                'normalized_name' => $index === 0 ? 'mcv10' : 'xv50',
                'year_from' => $index === 0 ? 1996 : 2011,
                'year_to' => $index === 0 ? 2001 : 2017,
                'active' => true,
            ]);
            $generations[] = $generation;
            \App\Models\VehicleEngineVariant::query()->create([
                'vehicle_model_id' => $model->id,
                'vehicle_generation_id' => $generation->id,
                'code' => $index === 0 ? '1MZ-FE' : '2AR-FE',
                'fingerprint' => hash('sha256', (string) $model->uuid),
                'active' => true,
            ]);
        }

        $this->assertCount(2, $this->service()->generations($this->manager, $first->uuid, '', 100));
        $this->assertCount(2, $this->service()->engines($this->manager, $first->uuid, null, '', 100));
        $engines = $this->service()->engines($this->manager, $first->uuid, $generations[1]->uuid, '', 100);
        $this->assertSame(['2AR-FE'], array_column($engines, 'name'));
        $this->assertCount(1, $this->service()->generations($this->manager, $first->uuid, '2017 v5', 100));
        $this->assertCount(1, $this->service()->engines($this->manager, $first->uuid, null, 'fe ar', 100));
        $this->assertCount(0, $this->service()->engines($this->manager, $first->uuid, null, 'fe absent', 100));
    }

    public function test_models_forbidden_for_station(): void
    {
        $make = $this->makeMake('Audi');

        $this->expectException(ApiException::class);

        $this->service()->models($this->station, $make->uuid, '', 10);
    }

    /* ─────────────────── create ─────────────────── */

    public function test_create_creates_make_and_model(): void
    {
        $result = $this->service()->create($this->customer, [
            'make'          => 'Audi',
            'model'         => 'A4',
            'modelYearFrom' => 2020,
            'modelYearTo'   => 2024,
            'engineIndex'   => 'B9',
            'horsepower'    => 150.5,
            'transmission'  => 'АКПП',
            'driveType'     => 'полный',
            'engineType'    => 'Бензин',
        ]);

        $this->assertSame('Audi', $result['make']);
        $this->assertSame('A4', $result['model']);
        $this->assertSame(2020, $result['modelYearFrom']);
        $this->assertSame(150.5, $result['horsepower']);
        $this->assertSame('АКПП', $result['transmission']);

        $this->assertSame(1, VehicleMake::query()->count());
        $this->assertSame(1, VehicleModel::query()->count());
    }

    public function test_create_is_idempotent_by_fingerprint(): void
    {
        $payload = [
            'make'        => 'Audi',
            'model'       => 'A4',
            'engineIndex' => 'B9',
        ];

        $first = $this->service()->create($this->customer, $payload);
        $second = $this->service()->create($this->customer, $payload);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, VehicleMake::query()->count());
        $this->assertSame(1, VehicleModel::query()->count());
    }

    public function test_create_creates_audit_event(): void
    {
        $this->service()->create($this->customer, [
            'make'        => 'Audi',
            'model'       => 'A4',
            'engineIndex' => 'B9',
        ]);

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'vehicleModels')
            ->where('action', 'create')
            ->count());
    }

    public function test_create_does_not_audit_on_repeat(): void
    {
        $payload = [
            'make'        => 'Audi',
            'model'       => 'A4',
            'engineIndex' => 'B9',
        ];

        $this->service()->create($this->customer, $payload);
        $this->service()->create($this->customer, $payload);

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'vehicleModels')
            ->count());
    }

    public function test_create_forbidden_for_station(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->create($this->station, [
            'make'  => 'Audi',
            'model' => 'A4',
        ]);
    }

    public function test_create_throws_validation_on_empty_make(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create($this->customer, [
            'make'  => '',
            'model' => 'A4',
        ]);
    }

    public function test_create_throws_validation_on_invalid_year(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create($this->customer, [
            'make'          => 'Audi',
            'model'         => 'A4',
            'modelYearFrom' => 1800,
        ]);
    }

    public function test_create_throws_on_year_to_before_year_from(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Модельный год до не может быть меньше модельного года от');

        $this->service()->create($this->customer, [
            'make'          => 'Audi',
            'model'         => 'A4',
            'modelYearFrom' => 2024,
            'modelYearTo'   => 2020,
        ]);
    }

    public function test_create_throws_validation_on_invalid_transmission(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create($this->customer, [
            'make'         => 'Audi',
            'model'        => 'A4',
            'transmission' => 'unknown',
        ]);
    }

    /* ─────────────────── static helpers ─────────────────── */

    public function test_clean_name_collapses_whitespace(): void
    {
        $this->assertSame('Aston Martin', VehicleReferenceService::cleanName('  Aston   Martin  '));
    }

    public function test_normalize_name_lowercases(): void
    {
        $this->assertSame('aston martin', VehicleReferenceService::normalizeName('Aston Martin'));
    }

    public function test_normalize_name_ignores_case_and_extra_spaces(): void
    {
        $this->assertSame(
            VehicleReferenceService::normalizeName('Audi A4'),
            VehicleReferenceService::normalizeName('  AUDI    A4  '),
        );
    }

    public function test_fingerprint_is_stable_for_same_input(): void
    {
        $attributes = [
            'name'            => 'A4',
            'model_year_from' => 2020,
            'model_year_to'   => 2024,
            'engine_index'    => 'B9',
            'horsepower'      => 150.5,
            'transmission'    => 'АКПП',
            'drive_type'      => 'полный',
            'engine_type'     => 'Бензин',
        ];

        $this->assertSame(
            VehicleReferenceService::fingerprint('Audi', $attributes),
            VehicleReferenceService::fingerprint('Audi', $attributes),
        );
    }

    public function test_fingerprint_differs_by_engine_index(): void
    {
        $base = [
            'name'         => 'A4',
            'engine_index' => 'B9',
        ];

        $this->assertNotSame(
            VehicleReferenceService::fingerprint('Audi', $base),
            VehicleReferenceService::fingerprint('Audi', array_replace($base, ['engine_index' => 'B8'])),
        );
    }

    public function test_fingerprint_ignores_name_casing(): void
    {
        $a = ['name' => 'A4', 'engine_index' => 'B9'];
        $b = ['name' => 'a4', 'engine_index' => 'b9'];

        $this->assertSame(
            VehicleReferenceService::fingerprint('Audi', $a),
            VehicleReferenceService::fingerprint('Audi', $b),
        );
    }

    /* ─────────────────── helpers ─────────────────── */

    private function service(): VehicleReferenceService
    {
        return app(VehicleReferenceService::class);
    }

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
