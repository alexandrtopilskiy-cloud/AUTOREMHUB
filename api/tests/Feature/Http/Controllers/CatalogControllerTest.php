<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Enums\OrganizationKind;
use App\Models\CatalogChangeEvent;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\TimeNorm;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkType;
use App\Services\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Feature-тест для CatalogController.
 *
 * Покрывает:
 *   - GET   /api/catalogs                       (index)
 *   - GET   /api/reference-data                 (reference)
 *   - GET   /api/catalogs/history               (history)
 *   - POST  /api/catalogs/{kind}                (store)
 *   - PATCH /api/catalogs/{id}                  (update)
 *   - POST  /api/catalogs/time-norms/import     (importTimeNorms)
 *   - GET   /api/catalogs/{kind}/import/template
 *   - POST  /api/catalogs/{kind}/import
 */
final class CatalogControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD_MASK = '••••••••••••';

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

        app(ReferenceSeeder::class)->seed($this->tenant->id);

        // NEW: создаём запись Station для organization
        Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->stationOrg->id,
            'name'            => 'СТО',
            'address'         => 'Москва',
            'active'          => true,
        ]);
    }

    private function partReferences(): array
    {
        $maker = \App\Models\PartManufacturer::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'normalized_name' => 'тест'],
            ['name' => 'Тест', 'status' => 'approved', 'active' => true],
        );
        $supplier = \App\Models\Supplier::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Поставщик теста'],
            ['active' => true],
        );
        return ['manufacturerId' => $maker->uuid, 'supplierId' => $supplier->uuid];
    }

    /* ─────────────────── index ─────────────────── */

    public function test_index_returns_all_records_for_manager(): void
    {
        $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeOrganizationPerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79990000001');

        $this->actingAs($this->manager);
        $response = $this->getJson('/api/catalogs')
            ->assertOk();

        $kinds = collect($response->json('records'))->pluck('kind')->unique()->values()->all();
        $this->assertContains('vehicles', $kinds);
        $this->assertContains('contactPersons', $kinds);
        $this->assertContains('stations', $kinds);
        $this->assertContains('customers', $kinds);
    }

    public function test_index_returns_only_own_vehicles_and_people_for_customer(): void
    {
        $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');
        $this->makeOrganizationPerson($this->customerOrg, 'contact', 'Иванов', 'Иван', '+79990000001');

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/catalogs')
            ->assertOk();

        $kinds = collect($response->json('records'))->pluck('kind')->unique()->values()->all();
        $this->assertContains('vehicles', $kinds);
        $this->assertContains('contactPersons', $kinds);
        $this->assertNotContains('stations', $kinds);
        $this->assertNotContains('customers', $kinds);
    }

    public function test_store_creates_customer_organization(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/customers', [
            'record' => [
                'name'     => 'ООО «Новый заказчик»',
                'contact'  => 'Иванов',
            ],
        ])->assertCreated();

        $org = Organization::query()
        ->where('name', 'ООО «Новый заказчик»')
            ->firstOrFail();

        $this->assertSame(OrganizationKind::Customer, $org->kind);
        $this->assertSame($this->tenant->id, $org->tenant_id);

        // Пользователи создаются отдельно (через UserDirectoryService)
        $this->assertSame(0, User::query()
        ->where('organization_id', $org->id)
            ->count());
    }

    public function test_store_creates_station_organization_and_profile(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/stations', [
            'record' => [
                'name'           => 'ООО «Новая СТО»',
                'address'        => 'Москва, ул. Ленина, 1',
                'lifts'          => 3,
                'mechanicsCount' => 5,
                'workdayStart'   => '10:00',
                'workdayEnd'     => '20:00',
                'workingDays'    => [1, 2, 3, 4, 5],
            ],
        ])->assertCreated();

        $org = Organization::query()
        ->where('name', 'ООО «Новая СТО»')
            ->firstOrFail();

        $station = Station::query()
        ->where('organization_id', $org->id)
            ->firstOrFail();

        $this->assertSame(3, $station->profile['lifts']);
        $this->assertSame(5, $station->profile['mechanicsCount']);
        $this->assertSame('10:00:00', $station->workday_start);
        $this->assertSame('20:00:00', $station->workday_end);
        $this->assertSame([1, 2, 3, 4, 5], $station->working_days);
    }

    public function test_store_station_requires_working_schedule(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/stations', [
            'record' => [
                'name'    => 'ООО «СТО без графика»',
                'address' => 'Москва',
            ],
        ])->assertUnprocessable();
    }

    public function test_store_station_rejects_invalid_working_hours(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/stations', [
            'record' => [
                'name'         => 'ООО «СТО с неверным графиком»',
                'address'      => 'Москва',
                'workdayStart' => '20:00',
                'workdayEnd'   => '10:00',
                'workingDays'  => [1, 2, 3, 4, 5],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath(
                'error.message',
                'Время окончания работы должно быть позже '
                . 'времени начала',
            );
    }

    public function test_index_returns_empty_for_station(): void
    {
        $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->station);
        $this->getJson('/api/catalogs')
            ->assertOk()
            ->assertJsonCount(0, 'records');
    }

    /* ─────────────────── reference ─────────────────── */

    public function test_reference_returns_all_sections_for_manager(): void
    {
        $this->actingAs($this->manager);
        $response = $this->getJson('/api/reference-data')
            ->assertOk();

        $response->assertJsonStructure([
            'partUnits',
            'workGroups',
            'reasons',
            'workTypes',
            'timeNorms',
            'normApplicabilities',
            'regulations',
            'suppliers',
            'parts',
        ]);
    }

    public function test_reference_hides_suppliers_and_parts_for_customer(): void
    {
        $this->actingAs($this->customer);
        $response = $this->getJson('/api/reference-data')
            ->assertOk();

        $this->assertSame([], $response->json('suppliers'));
        $this->assertSame([], $response->json('parts'));
        $this->assertNotEmpty($response->json('partUnits'));
    }

    public function test_reference_filters_reasons_for_customer(): void
    {
        // Дополнительная причина, недоступная customer
        ServiceReason::query()->create([
            'tenant_id'          => $this->tenant->id,
            'name'               => 'Ремонт',
            'code'               => 'repair',
            'requires_diagnosis' => false,
            'is_maintenance'     => false,
            'active'             => true,
        ]);

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/reference-data')->assertOk();

        $names = collect($response->json('reasons'))->pluck('name')->all();
        $this->assertNotContains('Ремонт', $names);
    }

    /* ─────────────────── history ─────────────────── */

    public function test_history_returns_events_for_manager(): void
    {
        $this->actingAs($this->manager);

        // Создаём событие через update CatalogPart'а? Нет — parts нельзя править.
        // Создадим workType через store, оно запишет событие.
        $this->postJson('/api/catalogs/workTypes', [
            'record' => ['name' => 'Диагностика', 'isMaintenance' => false, 'active' => true],
        ])->assertCreated();

        $this->getJson('/api/catalogs/history')
            ->assertOk()
            ->assertJsonPath('events.0.action', 'create');
    }

    public function test_history_forbidden_for_customer(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/catalogs/history')->assertForbidden();
    }

    public function test_history_forbidden_for_station(): void
    {
        $this->actingAs($this->station);
        $this->getJson('/api/catalogs/history')->assertForbidden();
    }

    public function test_history_filters_by_record_id(): void
    {
        $this->actingAs($this->manager);

        $workType = WorkType::query()->create([
            'tenant_id'      => $this->tenant->id,
            'name'           => 'Мойка',
            'is_maintenance' => false,
            'active'         => true,
        ]);
        CatalogChangeEvent::query()->create([
            'tenant_id'      => $this->tenant->id,
            'actor_id'       => $this->manager->id,
            'catalog_kind'   => 'workTypes',
            'record_uuid'    => $workType->uuid,
            'record_label'   => $workType->name,
            'action'         => 'create',
            'changed_fields' => ['name'],
            'occurred_at'    => now(),
        ]);

        $this->getJson('/api/catalogs/history?recordId=' . $workType->uuid)
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.recordId', $workType->uuid);
    }

    /* ─────────────────── store ─────────────────── */

    public function test_store_creates_work_type(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/workTypes', [
            'record' => [
                'name'          => 'Диагностика',
                'isMaintenance' => false,
                'active'        => true,
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('record.kind', 'workTypes')
            ->assertJsonPath('record.name', 'Диагностика');

        $this->assertDatabaseHas('work_types', [
            'tenant_id' => $this->tenant->id,
            'name'      => 'Диагностика',
        ]);
    }

    public function test_store_creates_catalog_part(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/parts', [
            'record' => [
                'name'         => 'Масляный фильтр',
                'article'      => 'OF-1',
                'manufacturerId' => $this->partReferences()['manufacturerId'],
                'supplierId' => $this->partReferences()['supplierId'],
                'purchasePrice' => 100, 'salePrice' => 150,
                'unit'         => 'шт.',
                'active'       => true,
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('catalog_parts', [
            'tenant_id' => $this->tenant->id,
            'article'   => 'OF-1',
        ]);
    }

    public function test_store_creates_reason(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/reasons', [
            'record' => [
                'name'              => 'Ремонт подвески',
                'code'              => 'suspension',
                'requiresDiagnosis' => true,
                'isMaintenance'     => false,
                'active'            => true,
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('service_reasons', [
            'tenant_id' => $this->tenant->id,
            'code'      => 'suspension',
        ]);
    }

    public function test_store_rejects_unknown_kind(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/unknownKind', [
            'record' => ['name' => 'X'],
        ])->assertUnprocessable();
    }

    public function test_store_forbidden_for_customer(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/catalogs/workTypes', [
            'record' => ['name' => 'Диагностика'],
        ])->assertForbidden();
    }

    public function test_store_rejects_extra_fields_in_body(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/catalogs/workTypes', [
            'record' => ['name' => 'Диагностика'],
            'extra'  => 'nope',
        ])->assertUnprocessable();
    }

    /* ─────────────────── update ─────────────────── */

    public function test_update_changes_vehicle_labor_rate_coefficient(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->manager);
        $this->patchJson('/api/catalogs/' . $vehicle->uuid, [
            'patch' => ['laborRateCoefficient' => 1.59],
        ])
            ->assertOk()
            ->assertJsonPath('record.laborRateCoefficient', 1.59);
    }

    public function test_update_masks_password_and_persists_new_one(): void
    {
        $this->actingAs($this->manager);

        $this->patchJson('/api/catalogs/' . $this->customer->uuid, [
            'patch' => ['password' => 'new-test-password-long'],
        ])
            ->assertOk()
            ->assertJsonPath('record.password', self::PASSWORD_MASK);

        $this->assertTrue(Hash::check(
            'new-test-password-long',
            $this->customer->refresh()->password,
        ));
    }

    public function test_update_allows_catalog_part(): void
    {
        $part = CatalogPart::query()->create([
            'tenant_id'    => $this->tenant->id,
            'name'         => 'Фильтр',
            'article'      => 'F-1',
            'manufacturer' => 'X',
            'unit'         => 'шт.',
            'active'       => true,
        ]);

        $this->actingAs($this->manager);
        $this->patchJson('/api/catalogs/' . $part->uuid, [
            'patch' => array_merge($this->partReferences(), ['name' => 'Другое', 'purchasePrice' => 100, 'salePrice' => 150]),
        ])->assertOk()->assertJsonPath('record.name', 'Другое');
    }

    public function test_update_forbidden_for_customer(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->customer);
        $this->patchJson('/api/catalogs/' . $vehicle->uuid, [
            'patch' => ['laborRateCoefficient' => 2.0],
        ])->assertForbidden();
    }

    public function test_update_returns_404_for_unknown_id(): void
    {
        $this->actingAs($this->manager);
        $this->patchJson('/api/catalogs/' . Str::uuid(), [
            'patch' => ['name' => 'X'],
        ])->assertNotFound();
    }

    public function test_update_rejects_extra_fields_in_body(): void
    {
        $vehicle = $this->makeVehicle($this->customerOrg, 'Lada Vesta', 'XTA00000000000001');

        $this->actingAs($this->manager);
        $this->patchJson('/api/catalogs/' . $vehicle->uuid, [
            'patch' => ['laborRateCoefficient' => 1.59],
            'extra' => 'nope',
        ])->assertUnprocessable();
    }

    /* ─────────────────── importTimeNorms ─────────────────── */

    public function test_import_time_norms_creates_work_type_and_norm(): void
    {
        $this->actingAs($this->manager);
        $file = UploadedFile::fake()->createWithContent(
            'time-norms.xlsx',
            $this->xlsx([
                ['Работа', 'Модель автомобиля', 'Норма, ч', 'Источник'],
                ['Замена масла', 'Lada Vesta', '0,48', 'Тестовый'],
            ]),
        );

        $this->post('/api/catalogs/time-norms/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('result.total', 1)
            ->assertJsonPath('result.created', 1)
            ->assertJsonPath('result.workTypesCreated', 1);

        $workType = WorkType::query()->where('name', 'Замена масла')->firstOrFail();
        $norm = TimeNorm::query()->where('work_type_id', $workType->id)->firstOrFail();
        $this->assertSame('0.480', $norm->hours);
    }

    public function test_import_time_norms_updates_existing_norm(): void
    {
        $workType = WorkType::query()->create([
            'tenant_id'      => $this->tenant->id,
            'name'           => 'Замена масла',
            'is_maintenance' => false,
            'active'         => true,
        ]);
        TimeNorm::query()->create([
            'work_type_id'  => $workType->id,
            'vehicle_model' => 'Lada Vesta',
            'hours'         => '0.480',
            'source'        => 'Старый',
        ]);

        $this->actingAs($this->manager);
        $file = UploadedFile::fake()->createWithContent(
            'time-norms.xlsx',
            $this->xlsx([
                ['Работа', 'Модель автомобиля', 'Норма, ч', 'Источник'],
                ['Замена масла', 'Lada Vesta', '0.50', 'Уточнённый'],
            ]),
        );

        $this->post('/api/catalogs/time-norms/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('result.created', 0)
            ->assertJsonPath('result.updated', 1);

        $this->assertSame(
            '0.500',
            TimeNorm::query()->where('work_type_id', $workType->id)->firstOrFail()->hours,
        );
    }

    public function test_import_time_norms_stores_empty_hours_as_null(): void
    {
        $this->actingAs($this->manager);
        $file = UploadedFile::fake()->createWithContent(
            'time-norms.xlsx',
            $this->xlsx([
                ['Работа', 'Модель автомобиля', 'Норма, ч', 'Источник'],
                ['Осмотр', 'Lada Vesta', '', 'Будет заполнено вручную'],
            ]),
        );

        $this->post('/api/catalogs/time-norms/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('result.total', 1);

        $workType = WorkType::query()->where('name', 'Осмотр')->firstOrFail();
        $this->assertNull(
            TimeNorm::query()->where('work_type_id', $workType->id)->firstOrFail()->hours,
        );
    }

    public function test_import_time_norms_forbidden_for_customer(): void
    {
        $this->actingAs($this->customer);
        $file = UploadedFile::fake()->createWithContent(
            'time-norms.xlsx',
            $this->xlsx([
                ['Работа', 'Модель автомобиля', 'Норма, ч', 'Источник'],
                ['Замена масла', 'Lada Vesta', '0.48', 'X'],
            ]),
        );

        $this->post('/api/catalogs/time-norms/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    public function test_import_time_norms_rejects_non_xlsx(): void
    {
        $this->actingAs($this->manager);
        $file = UploadedFile::fake()->create('not-xlsx.csv', 10, 'text/csv');

        $this->post('/api/catalogs/time-norms/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertUnprocessable();
    }

    /* ─────────────────── generic Excel import ─────────────────── */

    public function test_manager_downloads_catalog_import_template(): void
    {
        $this->actingAs($this->manager);
        $response = $this->get('/api/catalogs/parts/import/template');

        $response->assertOk()->assertDownload('template-parts.xlsx');
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type'),
        );
    }

    public function test_generic_import_creates_catalog_parts(): void
    {
        $this->partReferences();
        $this->actingAs($this->manager);
        $file = UploadedFile::fake()->createWithContent(
            'parts.xlsx',
            $this->xlsx([
                ['Наименование', 'Артикул', 'Производитель', 'Поставщик', 'Цена закупа', 'Цена реализации', 'Единица измерения', 'Активна'],
                ['Масляный фильтр', 'OF-100', 'Тест', 'Поставщик теста', '100', '150', 'шт.', 'Да'],
            ]),
        );

        $this->post('/api/catalogs/parts/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertCreated()
            ->assertJsonPath('result.success', true)
            ->assertJsonPath('result.rows', 1)
            ->assertJsonPath('result.imported', 1);

        $this->assertDatabaseHas('catalog_parts', [
            'tenant_id' => $this->tenant->id,
            'article'   => 'OF-100',
        ]);
    }

    public function test_generic_import_is_atomic_and_reports_excel_cell(): void
    {
        $this->partReferences();
        CatalogPart::query()->create([
            'tenant_id'    => $this->tenant->id,
            'name'         => 'Существующий фильтр',
            'article'      => 'DUP-1',
            'manufacturer' => 'Тест',
            'unit'         => 'шт.',
            'active'       => true,
        ]);

        $this->actingAs($this->manager);
        $file = UploadedFile::fake()->createWithContent(
            'parts.xlsx',
            $this->xlsx([
                ['Наименование', 'Артикул', 'Производитель', 'Поставщик', 'Цена закупа', 'Цена реализации', 'Единица измерения', 'Активна'],
                ['Новая запись', 'NEW-1', 'Тест', 'Поставщик теста', '100', '150', 'шт.', 'Да'],
                ['Дубликат', 'DUP-1', 'Тест', 'Поставщик теста', '100', '150', 'шт.', 'Да'],
            ]),
        );

        $this->post('/api/catalogs/parts/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('result.success', false)
            ->assertJsonPath('result.imported', 0)
            ->assertJsonPath('result.conflicts.0.row', 3)
            ->assertJsonPath('result.conflicts.0.cell', 'B3');

        $this->assertDatabaseMissing('catalog_parts', [
            'tenant_id' => $this->tenant->id,
            'article'   => 'NEW-1',
        ]);
    }

    public function test_generic_import_is_forbidden_outside_manager_cabinet(): void
    {
        $this->actingAs($this->customer);
        $file = UploadedFile::fake()->createWithContent(
            'parts.xlsx',
            $this->xlsx([
                ['Наименование', 'Артикул', 'Производитель', 'Поставщик', 'Цена закупа', 'Цена реализации', 'Единица измерения', 'Активна'],
                ['Фильтр', 'OF-1', 'Тест', 'шт.', 'Да'],
            ]),
        );

        $this->post('/api/catalogs/parts/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    /* ─────────────────── helpers ─────────────────── */

    private function makeVehicle(
        Organization $organization,
        string $name,
        string $vin,
    ): Vehicle {
        return Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $organization->id,
            'name'             => $name,
            'vin'              => $vin,
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
        ]);
    }

    private function makeOrganizationPerson(
        Organization $organization,
        string $kind,
        string $lastName,
        string $firstName,
        string $phone,
    ): OrganizationPerson {
        return OrganizationPerson::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $organization->id,
            'kind'            => $kind,
            'last_name'       => $lastName,
            'first_name'      => $firstName,
            'phone'           => $phone,
            'active'          => true,
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

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'time-norms-');

        if ($path === false) {
            self::fail('Не удалось создать временный XLSX');
        }

        $xmlRows = '';

        foreach ($rows as $rowIndex => $row) {
            $cells = '';

            foreach ($row as $columnIndex => $value) {
                $column = chr(65 + $columnIndex);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<c r="' . $column . ($rowIndex + 1) . '" t="inlineStr">'
                . '<is><t>' . $escaped . '</t></is></c>';
            }

            $xmlRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $xmlRows . '</sheetData></worksheet>',
        );
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        if ($content === false) {
            self::fail('Не удалось прочитать временный XLSX');
        }

        return $content;
    }
}
