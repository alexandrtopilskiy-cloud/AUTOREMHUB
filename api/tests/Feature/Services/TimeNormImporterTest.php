<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\TimeNorm;
use App\Models\TimeNormApplicability;
use App\Models\User;
use App\Models\WorkType;
use App\Services\TimeNormImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\CreatesXlsxFiles;
use Tests\TestCase;

final class TimeNormImporterTest extends TestCase
{
    use CreatesXlsxFiles;
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $managerOrganization;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        $this->managerOrganization = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'manager',
            'name'      => 'ООО «УК»',
        ]);
        $this->manager = $this->makeUser(
            $this->managerOrganization,
            'manager',
        );
    }

    protected function tearDown(): void
    {
        $this->deleteXlsxFiles();

        parent::tearDown();
    }

    public function test_import_creates_work_types_norms_and_audit_events(): void
    {
        $path = $this->xlsx([
            ['Работа', 'Модель', 'Норма времени', 'Источник'],
            ['Замена масла', '', '1,5', 'Каталог'],
            ['Диагностика подвески', 'Jetour Dashing', '', 'Ручной ввод'],
        ]);

        $result = $this->importer()->import(
            $this->tenant->id,
            $path,
            $this->manager,
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['workTypesCreated']);
        $this->assertSame(2, $result['total']);
        $this->assertFalse($result['dryRun']);
        $this->assertSame(2, WorkType::query()->count());
        $this->assertSame(2, TimeNorm::query()->count());
        $this->assertSame(2, TimeNormApplicability::query()->count());
        $this->assertSame(4, CatalogChangeEvent::query()->count());

        $general = TimeNorm::query()
            ->whereHas('workType', fn ($query) => $query->where('name', 'Замена масла'))
            ->firstOrFail();
        $special = TimeNorm::query()
            ->whereHas(
                'workType',
                fn ($query) => $query->where('name', 'Диагностика подвески'),
            )
            ->firstOrFail();

        $this->assertSame('1.500', $general->hours);
        $this->assertSame('general', $general->applicability?->code);
        $this->assertNull($special->hours);
        $this->assertSame('special', $special->applicability?->code);
        $this->assertSame('Jetour Dashing', $special->vehicle_model);
    }

    public function test_import_updates_existing_norm_without_changing_work_catalog(): void
    {
        $first = $this->xlsx([
            ['Работа', 'Модель', 'Норма времени', 'Источник'],
            ['Замена масла', '', '1,5', 'Каталог'],
        ]);
        $second = $this->xlsx([
            ['Работа', 'Модель', 'Норма времени', 'Источник'],
            ['Замена масла', '', '2,25', 'Уточнение УК'],
        ]);

        $this->importer()->import(
            $this->tenant->id,
            $first,
            $this->manager,
        );
        $result = $this->importer()->import(
            $this->tenant->id,
            $second,
            $this->manager,
        );

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['workTypesCreated']);
        $this->assertSame(1, WorkType::query()->count());
        $this->assertSame('2.250', TimeNorm::query()->firstOrFail()->hours);
        $this->assertSame(
            'import_update',
            CatalogChangeEvent::query()->latest('id')->firstOrFail()->action,
        );
    }

    public function test_dry_run_reports_rows_without_writing_to_database(): void
    {
        $path = $this->xlsx([
            ['Наименование работы', 'Норма, ч'],
            ['Замена фильтра', '0,5'],
            ['Замена фильтра', '0,75'],
        ]);

        $result = $this->importer()->import(
            $this->tenant->id,
            $path,
            $this->manager,
            true,
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['workTypesCreated']);
        $this->assertSame(0, WorkType::query()->count());
        $this->assertSame(0, TimeNorm::query()->count());
        $this->assertSame(0, TimeNormApplicability::query()->count());
        $this->assertSame(0, CatalogChangeEvent::query()->count());
    }

    public function test_import_rejects_invalid_time_norm(): void
    {
        $path = $this->xlsx([
            ['Работа', 'Норма времени'],
            ['Замена масла', 'два часа'],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(
            'норма времени должна быть положительным числом',
        );

        $this->importer()->import(
            $this->tenant->id,
            $path,
            $this->manager,
        );
    }

    public function test_import_is_forbidden_for_customer(): void
    {
        $customerOrganization = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
        $customer = $this->makeUser($customerOrganization, 'customer');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('доступен только Управляющей компании');

        $this->importer()->import(
            $this->tenant->id,
            '/tmp/not-read-forbidden.xlsx',
            $customer,
        );
    }

    public function test_import_is_forbidden_for_manager_from_another_tenant(): void
    {
        $foreignTenant = Tenant::query()->create(['name' => 'Другая УК']);
        $foreignOrganization = Organization::query()->create([
            'tenant_id' => $foreignTenant->id,
            'kind'      => 'manager',
            'name'      => 'ООО «Другая УК»',
        ]);
        $foreignManager = $this->makeUser(
            $foreignOrganization,
            'manager',
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('доступен только Управляющей компании');

        $this->importer()->import(
            $this->tenant->id,
            '/tmp/not-read-foreign.xlsx',
            $foreignManager,
        );
    }

    private function importer(): TimeNormImporter
    {
        return app(TimeNormImporter::class);
    }

    /**
     * @param array<int, array<int, scalar|null>> $rows
     */
    private function xlsx(array $rows): string
    {
        return $this->createXlsxFile($rows, 'time-norm-import-');
    }

    private function makeUser(
        Organization $organization,
        string $role,
    ): User {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $role . '-' . Str::random(12),
            'display_name'    => ucfirst($role),
            'role'            => $role,
            'password'        => 'test-password-long',
            'active'          => true,
        ]);
    }
}
