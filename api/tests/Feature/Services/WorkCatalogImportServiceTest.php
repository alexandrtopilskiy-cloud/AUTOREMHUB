<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestWork;
use App\Models\Tenant;
use App\Models\TimeNorm;
use App\Models\TimeNormApplicability;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\WorkCatalogImport;
use App\Models\WorkType;
use App\Services\WorkCatalogImportService;
use App\Services\WorkCatalogValidator;
use App\Services\Workflow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class WorkCatalogImportServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private User $manager;

    private Organization $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'УК импорта']);
        $manager = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'manager',
            'name' => 'УК',
        ]);
        $this->customer = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'customer',
            'name' => 'Заказчик',
        ]);
        $this->manager = User::query()->create([
            'organization_id' => $manager->id,
            'login' => 'catalog-' . Str::random(10),
            'display_name' => 'Менеджер импорта',
            'role' => 'manager',
            'password' => 'test-password-long',
            'active' => true,
        ]);
    }

    public function test_validator_rejects_ambiguous_rules_and_keeps_empty_norm(): void
    {
        $validated = app(WorkCatalogValidator::class)->validate(
            $this->manager,
            [
                'works' => [$this->workRow()],
                'norms' => [
                    $this->normRow('OT-00001-N001', ''),
                    $this->normRow('OT-00001-N002', '1.500', row: 3),
                ],
                'errors' => [],
            ],
        );

        $this->assertSame(1, $validated['summary']['emptyNorms']);
        $this->assertSame(1, $validated['summary']['confirmedNorms']);
        $this->assertContains(
            'AMBIGUOUS_NORM_RULE',
            array_column($validated['errors'], 'code'),
        );
    }

    public function test_validator_checks_model_make_and_generation_model_links(): void
    {
        $suffix = mb_strtolower(Str::random(8));
        $firstMake = VehicleMake::query()->create([
            'name' => 'Make ' . $suffix,
            'normalized_name' => 'make ' . $suffix,
            'active' => true,
        ]);
        $secondMake = VehicleMake::query()->create([
            'name' => 'Other ' . $suffix,
            'normalized_name' => 'other ' . $suffix,
            'active' => true,
        ]);
        $firstModel = VehicleModel::query()->create([
            'vehicle_make_id' => $firstMake->id,
            'name' => 'Model A',
            'normalized_name' => 'model a',
            'fingerprint' => hash('sha256', 'model-a-' . $suffix),
            'active' => true,
        ]);
        $secondModel = VehicleModel::query()->create([
            'vehicle_make_id' => $secondMake->id,
            'name' => 'Model B',
            'normalized_name' => 'model b',
            'fingerprint' => hash('sha256', 'model-b-' . $suffix),
            'active' => true,
        ]);
        VehicleGeneration::query()->create([
            'vehicle_model_id' => $secondModel->id,
            'name' => 'Wrong generation',
            'normalized_name' => 'wrong generation',
            'active' => true,
        ]);

        $validated = app(WorkCatalogValidator::class)->validate(
            $this->manager,
            [
                'works' => [$this->workRow()],
                'norms' => [
                    $this->normRow(
                        'OT-00001-N001',
                        '1.000',
                        overrides: [
                            'make' => $firstMake->name,
                            'model' => $secondModel->name,
                        ],
                    ),
                    $this->normRow(
                        'OT-00001-N002',
                        '1.000',
                        row: 3,
                        overrides: [
                            'make' => $firstMake->name,
                            'model' => $firstModel->name,
                            'generation' => 'Wrong generation',
                            'engine' => 'TEST',
                        ],
                    ),
                ],
                'errors' => [],
            ],
        );

        $referenceErrors = array_values(array_filter(
            $validated['errors'],
            static fn (array $error): bool => $error['code']
                === 'INVALID_APPLICABILITY',
        ));

        $this->assertCount(2, $referenceErrors);
        $this->assertSame('model', $referenceErrors[0]['field']);
        $this->assertSame('generation', $referenceErrors[1]['field']);
    }

    public function test_replacement_archives_old_catalog_and_preserves_history(): void
    {
        [$oldWork, $oldNorm, $line] = $this->historicalLine();

        $result = $this->replaceCatalog($this->preview());

        $this->assertTrue($result['applied']);
        $this->assertFalse($oldWork->refresh()->active);
        $this->assertNotNull($oldWork->archived_at);
        $this->assertFalse($oldNorm->refresh()->active);
        $this->assertSame('3500.00', $line->refresh()->line_amount);
        $this->assertSame('Старая работа - ремонт', $line->name);
        $this->assertDatabaseHas('request_works', [
            'id' => $line->id,
            'work_type_id' => $oldWork->id,
            'time_norm_id' => $oldNorm->id,
            'line_amount' => '3500.00',
        ]);
        $this->assertDatabaseHas('work_types', [
            'tenant_id' => $this->tenant->id,
            'code' => 'EN-00001',
            'name' => 'Новая работа - проверка',
            'active' => true,
        ]);
        $this->assertDatabaseHas('time_norms', [
            'tenant_id' => $this->tenant->id,
            'norm_id' => 'EN-00001-N001',
            'hours' => null,
            'active' => true,
        ]);
    }

    public function test_failed_replacement_rolls_back_archiving(): void
    {
        [$oldWork] = $this->historicalLine();
        $preview = $this->preview();
        $preview['works'][] = $preview['works'][0];

        try {
            $this->replaceCatalog($preview);
            $this->fail('Ожидалась ошибка уникальности кода работы');
        } catch (QueryException) {
            $this->assertTrue($oldWork->refresh()->active);
            $this->assertNull($oldWork->archived_at);
            $this->assertSame(0, WorkCatalogImport::query()->count());
            $this->assertSame(1, WorkType::query()->where('active', true)->count());
        }
    }

    /** @return array{0: WorkType, 1: TimeNorm, 2: RequestWork} */
    private function historicalLine(): array
    {
        $work = WorkType::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Старая работа - ремонт',
            'group_name' => 'Прочее',
            'active' => true,
        ]);
        $applicability = TimeNormApplicability::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'general',
            'name' => 'Общая',
            'normalized_name' => 'общая',
            'is_system' => true,
            'active' => true,
        ]);
        $norm = TimeNorm::query()->create([
            'tenant_id' => $this->tenant->id,
            'norm_id' => 'OT-99999-N001',
            'work_type_id' => $work->id,
            'applicability_id' => $applicability->id,
            'vehicle_model' => '',
            'hours' => '1.000',
            'source' => 'Старый источник',
            'active' => true,
            'catalog_version' => 'legacy-test',
        ]);
        $uuid = (string) Str::uuid();
        $request = RepairRequest::query()->create([
            'uuid' => $uuid,
            'public_id' => $uuid,
            'tenant_id' => $this->tenant->id,
            'customer_organization_id' => $this->customer->id,
            'created_by' => $this->manager->id,
            'data' => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works' => [],
            'history' => [],
            'stage' => Workflow::STAGE_READY,
            'revision' => 2,
        ]);
        $line = RequestWork::query()->create([
            'repair_request_id' => $request->id,
            'work_type_id' => $work->id,
            'time_norm_id' => $norm->id,
            'name' => $work->name,
            'work_code_snapshot' => $work->code,
            'norm_id_snapshot' => $norm->norm_id,
            'norm_selection_scope' => 'general',
            'quantity' => '1.000',
            'price' => '3500.00',
            'hourly_rate' => '3500.00',
            'norm_hours' => '1.000',
            'line_amount' => '3500.00',
            'is_maintenance' => false,
        ]);

        return [$work, $norm, $line];
    }

    /** @return array<string, mixed> */
    private function preview(): array
    {
        return [
            'fileName' => 'catalog.xlsx',
            'checksum' => hash('sha256', Str::random(30)),
            'catalogVersion' => 'catalog-test-' . Str::random(8),
            'canApply' => true,
            'summary' => [
                'workRows' => 1,
                'acceptedWorks' => 1,
                'normRows' => 1,
                'acceptedNorms' => 1,
                'confirmedNorms' => 0,
                'emptyNorms' => 1,
                'errors' => 0,
            ],
            'errors' => [],
            'warnings' => [],
            'works' => [[
                'row' => 2,
                'code' => 'EN-00001',
                'group_code' => 'EN',
                'group_name' => 'Двигатель',
                'name' => 'Новая работа - проверка',
                'is_maintenance' => false,
                'active' => true,
                'source_row' => 10,
                'source_name' => 'Тестовый файл',
            ]],
            'norms' => [[
                'normId' => 'EN-00001-N001',
                'workCode' => 'EN-00001',
                'specificity' => 'general',
                'vehicle_id' => null,
                'vehicle_make_id' => null,
                'vehicle_model_id' => null,
                'vehicle_generation_id' => null,
                'vehicle_engine_variant_id' => null,
                'vehicle_transmission_type_id' => null,
                'vehicle_drive_type_id' => null,
                'modification_code' => null,
                'hours' => null,
                'source' => 'Тестовый файл',
                'active' => true,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function workRow(): array
    {
        return [
            '_row' => 2,
            '_sheet' => 'Работы',
            '_cells' => [],
            'workCode' => 'OT-00001',
            'groupCode' => 'OT',
            'group' => 'Прочее',
            'name' => 'Тестовая деталь - проверка',
            'isMaintenance' => 'Нет',
            'active' => 'Да',
            'sourceRow' => '10',
            'source' => 'Тестовый файл',
        ];
    }

    /** @return array<string, mixed> */
    private function normRow(
        string $normId,
        string $hours,
        int $row = 2,
        array $overrides = [],
    ): array {
        return array_replace([
            '_row' => $row,
            '_sheet' => 'Нормы времени',
            '_cells' => [],
            'normId' => $normId,
            'workCode' => 'OT-00001',
            'hours' => $hours,
            'vin' => '',
            'make' => '',
            'model' => '',
            'generation' => '',
            'engine' => '',
            'transmission' => '',
            'drive' => '',
            'modificationCode' => '',
            'active' => 'Да',
            'source' => 'Тестовый файл',
        ], $overrides);
    }

    /** @param array<string, mixed> $preview @return array<string, mixed> */
    private function replaceCatalog(array $preview): array
    {
        $method = new ReflectionMethod(
            WorkCatalogImportService::class,
            'replaceCatalog',
        );
        $method->setAccessible(true);

        return $method->invoke(
            app(WorkCatalogImportService::class),
            $this->manager,
            $preview,
        );
    }
}
