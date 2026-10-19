<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\RepairStage;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\TimeNorm;
use App\Models\TimeNormApplicability;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDriveType;
use App\Models\VehicleEngineVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleTransmissionType;
use App\Models\WorkType;
use App\Services\CatalogService;
use App\Services\RepairRequestService;
use App\Services\TimeNormSelectionService;
use App\Services\WorkCostService;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class WorkCatalogDomainTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Organization $organization;

    private User $manager;

    private VehicleMake $make;

    private VehicleModel $model;

    private VehicleGeneration $generation;

    private VehicleEngineVariant $engine;

    private VehicleTransmissionType $transmission;

    private VehicleDriveType $drive;

    private Vehicle $vehicle;

    private WorkType $work;

    private TimeNormApplicability $general;

    private TimeNormApplicability $byVehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Тест норм']);
        $this->organization = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'manager',
            'name' => 'УК тест',
        ]);
        $this->manager = User::query()->create([
            'organization_id' => $this->organization->id,
            'login' => 'manager-' . Str::random(10),
            'display_name' => 'Менеджер',
            'role' => 'manager',
            'password' => 'test-password-long',
            'active' => true,
        ]);
        $this->make = VehicleMake::query()->create([
            'name' => 'TestMake',
            'normalized_name' => 'testmake-' . Str::random(8),
            'active' => true,
        ]);
        $this->model = VehicleModel::query()->create([
            'vehicle_make_id' => $this->make->id,
            'name' => 'TestModel',
            'normalized_name' => 'testmodel',
            'fingerprint' => hash('sha256', Str::random(20)),
            'active' => true,
        ]);
        $this->generation = VehicleGeneration::query()->create([
            'vehicle_model_id' => $this->model->id,
            'name' => 'I',
            'normalized_name' => 'i',
            'active' => true,
        ]);
        $this->engine = VehicleEngineVariant::query()->create([
            'vehicle_model_id' => $this->model->id,
            'vehicle_generation_id' => $this->generation->id,
            'code' => 'TST-20',
            'fingerprint' => hash('sha256', Str::random(20)),
            'active' => true,
        ]);
        $this->transmission = VehicleTransmissionType::query()
            ->where('code', 'automatic')
            ->firstOrFail();
        $this->drive = VehicleDriveType::query()
            ->where('code', 'all')
            ->firstOrFail();
        $this->vehicle = Vehicle::query()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $this->organization->id,
            'name' => 'TestMake TestModel',
            'make' => 'TestMake',
            'model' => 'TestModel',
            'vehicle_model_id' => $this->model->id,
            'vehicle_generation_id' => $this->generation->id,
            'vehicle_engine_variant_id' => $this->engine->id,
            'vehicle_transmission_type_id' => $this->transmission->id,
            'vehicle_drive_type_id' => $this->drive->id,
            'modification_code' => 'A1',
            'vin' => 'TST00000000000001',
            'plate' => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'labor_hour_rate' => '2400.00',
        ]);
        $this->work = WorkType::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Тестовая деталь - с/у',
            'group_name' => 'Прочее',
            'active' => true,
        ]);
        $this->general = $this->applicability('general', 'Общая');
        $this->byVehicle = $this->applicability('vehicle', 'По авто');
    }

    public function test_model_norm_applies_to_sibling_technical_variants(): void
    {
        $sibling = $this->model->replicate(['uuid']);
        $sibling->fingerprint = hash('sha256', 'sibling-' . $this->model->uuid);
        $sibling->save();
        $this->norm('FAMILY-N001', '2.000', ['vehicle_make_id' => $this->make->id, 'vehicle_model_id' => $sibling->id]);
        $result = app(TimeNormSelectionService::class)->select($this->work, $this->vehicle);
        $this->assertSame('selected', $result['status']);
        $this->assertSame('2.000', $result['norm']->hours);
        $saved = app(CatalogService::class)->create($this->manager, 'workTypes', [
            'name' => 'Норма для поколения другого варианта', 'group' => 'Двигатель',
            'timeNorms' => [[
                'applicabilityId' => $this->byVehicle->uuid,
                'vehicleMakeId' => $this->make->uuid,
                'vehicleModelId' => $sibling->uuid,
                'vehicleGenerationId' => $this->generation->uuid,
                'vehicleEngineId' => $this->engine->uuid,
                'hours' => 2.0, 'source' => 'Тест',
            ]],
        ]);
        $this->assertSame($this->generation->uuid, $saved['timeNorms'][0]['vehicleGenerationId']);

    }

    public function test_general_norm_is_selected(): void
    {
        $general = $this->norm('OT-00001-N001', '1.250');

        $result = $this->selector()->select($this->work, $this->vehicle);

        $this->assertSame(TimeNormSelectionService::STATUS_SELECTED, $result['status']);
        $this->assertTrue($general->is($result['norm']));
        $this->assertSame('general', $result['scope']);
    }

    public function test_generation_and_modification_win_over_broader_norms(): void
    {
        $this->norm('OT-00001-N001', '1.000');
        $this->norm('OT-00001-N002', '1.100', [
            'vehicle_make_id' => $this->make->id,
        ]);
        $this->norm('OT-00001-N003', '1.200', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
        ]);
        $specific = $this->norm('OT-00001-N004', '1.300', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
            'vehicle_generation_id' => $this->generation->id,
            'vehicle_engine_variant_id' => $this->engine->id,
        ]);

        $result = $this->selector()->select($this->work, $this->vehicle);

        $this->assertTrue($specific->is($result['norm']));
        $this->assertSame('generation_modification', $result['scope']);
    }

    public function test_make_and_model_norm_win_over_make_and_general_norms(): void
    {
        $this->norm('OT-00001-N001', '1.000');
        $this->norm('OT-00001-N002', '1.100', [
            'vehicle_make_id' => $this->make->id,
        ]);
        $special = $this->norm('OT-00001-N003', '1.200', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
        ]);

        $result = $this->selector()->select($this->work, $this->vehicle);

        $this->assertTrue($special->is($result['norm']));
        $this->assertSame('make_model', $result['scope']);
    }

    public function test_generation_norm_wins_over_make_and_model_norm(): void
    {
        $this->norm('OT-00001-N001', '1.000', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
        ]);
        $generation = $this->norm('OT-00001-N002', '1.200', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
            'vehicle_generation_id' => $this->generation->id,
        ]);

        $result = $this->selector()->select($this->work, $this->vehicle);

        $this->assertTrue($generation->is($result['norm']));
        $this->assertSame('generation', $result['scope']);
    }

    public function test_exact_vehicle_norm_has_highest_priority(): void
    {
        $this->norm('OT-00001-N001', '1.000');
        $this->norm('OT-00001-N002', '1.300', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
            'vehicle_generation_id' => $this->generation->id,
            'vehicle_engine_variant_id' => $this->engine->id,
        ]);
        $exact = $this->norm('OT-00001-N003', '0.900', [
            'vehicle_id' => $this->vehicle->id,
        ]);

        $result = $this->selector()->select($this->work, $this->vehicle);

        $this->assertTrue($exact->is($result['norm']));
        $this->assertSame('vehicle', $result['scope']);
    }

    public function test_work_directory_saves_all_vehicle_applicability_dimensions(): void
    {
        $result = app(CatalogService::class)->create(
            $this->manager,
            'workTypes',
            [
                'name'      => 'Работа с полной применимостью',
                'group'     => 'Двигатель',
                'timeNorms' => [[
                    'applicabilityId'       => $this->byVehicle->uuid,
                    'vehicleMakeId'         => $this->make->uuid,
                    'vehicleModelId'        => $this->model->uuid,
                    'vehicleGenerationId'   => $this->generation->uuid,
                    'vehicleEngineId'       => $this->engine->uuid,
                    'vehicleTransmissionId' => $this->transmission->uuid,
                    'vehicleDriveId'        => $this->drive->uuid,
                    'modificationCode'      => 'A1',
                    'hours'                 => 1.75,
                    'source'                => 'Технологическая карта',
                ]],
            ],
        );
        $norm = $result['timeNorms'][0];

        $this->assertSame($this->make->uuid, $norm['vehicleMakeId']);
        $this->assertSame($this->model->uuid, $norm['vehicleModelId']);
        $this->assertSame($this->generation->uuid, $norm['vehicleGenerationId']);
        $this->assertSame($this->engine->uuid, $norm['vehicleEngineId']);
        $this->assertSame($this->transmission->uuid, $norm['vehicleTransmissionId']);
        $this->assertSame($this->drive->uuid, $norm['vehicleDriveId']);
        $this->assertSame('A1', $norm['modificationCode']);
    }

    public function test_work_directory_saves_generation_only_with_empty_hours(): void
    {
        $result = app(CatalogService::class)->create(
            $this->manager,
            'workTypes',
            [
                'name'      => 'Работа для поколения',
                'group'     => 'Прочее',
                'timeNorms' => [[
                    'applicabilityId'     => $this->byVehicle->uuid,
                    'vehicleMakeId'       => $this->make->uuid,
                    'vehicleModelId'      => $this->model->uuid,
                    'vehicleGenerationId' => $this->generation->uuid,
                    'hours'               => null,
                    'source'              => '',
                ]],
            ],
        );
        $norm = $result['timeNorms'][0];

        $this->assertSame($this->generation->uuid, $norm['vehicleGenerationId']);
        $this->assertSame('', $norm['vehicleEngineId']);
        $this->assertNull($norm['hours']);
    }

    public function test_equal_priority_overlapping_rules_are_a_conflict(): void
    {
        $this->norm('OT-00001-N001', '1.300', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
            'vehicle_generation_id' => $this->generation->id,
            'vehicle_engine_variant_id' => $this->engine->id,
        ]);
        $this->norm('OT-00001-N002', '1.400', [
            'vehicle_make_id' => $this->make->id,
            'vehicle_model_id' => $this->model->id,
            'vehicle_generation_id' => $this->generation->id,
            'vehicle_drive_type_id' => $this->drive->id,
        ]);

        $result = $this->selector()->select($this->work, $this->vehicle);

        $this->assertSame(TimeNormSelectionService::STATUS_CONFLICT, $result['status']);
        $this->assertCount(2, $result['conflicts']);
    }

    public function test_empty_norm_is_not_converted_to_zero(): void
    {
        $norm = $this->norm('OT-00001-N001', null);

        $result = $this->selector()->select($this->work, $this->vehicle);

        $this->assertTrue($norm->is($result['norm']));
        $this->assertNull($result['norm']->hours);
    }

    public function test_empty_norm_keeps_amount_null_and_blocks_approval(): void
    {
        $norm = $this->norm('OT-00001-N001', null);
        $request = $this->request();

        $this->replaceWorks($request, [[
            'name' => $this->work->name,
            'workTypeId' => $this->work->uuid,
            'timeNormId' => $norm->uuid,
            'qty' => 1,
            'normHours' => null,
        ]]);

        $line = $request->workLines()->firstOrFail();
        $this->assertNull($line->norm_hours);
        $this->assertNull($line->price);
        $this->assertNull($line->line_amount);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('выберите норму времени');
        $this->validateManagerReview($request);
    }

    public function test_cost_is_norm_times_rate_times_quantity(): void
    {
        $result = app(WorkCostService::class)->calculate('1.250', '2400.00', '2.000');

        $this->assertSame('3000.00', $result['unitPrice']);
        $this->assertSame('6000.00', $result['amount']);
    }

    public function test_labor_rate_coefficient_is_not_used_in_line_amount(): void
    {
        $this->vehicle->forceFill(['labor_rate_coefficient' => '1.590'])->save();
        $norm = $this->norm('OT-00001-N001', '1.000');
        $request = $this->request();

        $this->replaceWorks($request, [[
            'name' => $this->work->name,
            'workTypeId' => $this->work->uuid,
            'timeNormId' => $norm->uuid,
            'qty' => 1,
            'normHours' => '1.000',
        ]]);

        $line = $request->workLines()->firstOrFail();
        $this->assertSame('2400.00', $line->line_amount);
    }

    public function test_manual_norm_requires_reason_and_is_snapshotted(): void
    {
        $norm = $this->norm('OT-00001-N001', '1.000');
        $request = $this->request();

        $this->replaceWorks($request, [[
            'name' => $this->work->name,
            'workTypeId' => $this->work->uuid,
            'timeNormId' => $norm->uuid,
            'qty' => 2,
            'normHours' => '1.250',
            'normIsManual' => true,
            'normOverrideReason' => 'Коррозия крепежа',
        ]]);

        $line = $request->workLines()->firstOrFail();
        $this->assertSame('1.250', $line->norm_hours);
        $this->assertSame('2400.00', $line->hourly_rate);
        $this->assertSame('6000.00', $line->line_amount);
        $this->assertSame('OT-00001-N001', $line->norm_id_snapshot);
        $this->assertSame($this->work->name, $line->name);
        $this->assertTrue($line->norm_is_manual);
        $this->assertSame('Коррозия крепежа', $line->norm_override_reason);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('причину корректировки');
        $this->replaceWorks($request, [[
            'name' => $this->work->name,
            'workTypeId' => $this->work->uuid,
            'timeNormId' => $norm->uuid,
            'qty' => 1,
            'normHours' => '1.500',
            'normIsManual' => true,
            'normOverrideReason' => '',
        ]]);
    }

    private function applicability(string $code, string $name): TimeNormApplicability
    {
        return TimeNormApplicability::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'is_system' => true,
            'active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function norm(
        string $normId,
        ?string $hours,
        array $attributes = [],
    ): TimeNorm {
        return TimeNorm::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'norm_id' => $normId,
            'work_type_id' => $this->work->id,
            'applicability_id' => $attributes === []
                ? $this->general->id
                : $this->byVehicle->id,
            'vehicle_model' => '',
            'hours' => $hours,
            'source' => 'Тест',
            'active' => true,
            'catalog_version' => 'test',
        ], $attributes));
    }

    private function selector(): TimeNormSelectionService
    {
        return app(TimeNormSelectionService::class);
    }

    private function request(): RepairRequest
    {
        $uuid = (string) Str::uuid();

        return RepairRequest::query()->create([
            'uuid' => $uuid,
            'public_id' => $uuid,
            'tenant_id' => $this->tenant->id,
            'customer_organization_id' => $this->organization->id,
            'vehicle_id' => $this->vehicle->id,
            'data' => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works' => [],
            'history' => [],
            'stage' => RepairStage::ManagerRouting,
            'revision' => 1,
            'created_by' => $this->manager->id,
        ]);
    }

    /** @param array<int, array<string, mixed>> $works */
    private function replaceWorks(RepairRequest $request, array $works): void
    {
        $method = new ReflectionMethod(RepairRequestService::class, 'replaceWorks');
        $method->setAccessible(true);
        $method->invoke(app(RepairRequestService::class), $request, $works);
    }

    private function validateManagerReview(RepairRequest $request): void
    {
        $method = new ReflectionMethod(
            RepairRequestService::class,
            'validateManagerReviewStage',
        );
        $method->setAccessible(true);
        $method->invoke(app(RepairRequestService::class), $request);
    }
}
