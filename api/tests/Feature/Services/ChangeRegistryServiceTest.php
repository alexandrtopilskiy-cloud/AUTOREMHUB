<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChangeRegistryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ChangeRegistryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $managerOrganization;
    private Organization $customerOrganization;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        $this->managerOrganization = $this->makeOrganization(
            $this->tenant,
            'manager',
            'ООО «УК»',
        );
        $this->customerOrganization = $this->makeOrganization(
            $this->tenant,
            'customer',
            'ООО «Заказчик»',
        );
        $this->manager = $this->makeUser(
            $this->managerOrganization,
            'manager',
            'Мария Менеджер',
        );
    }

    public function test_records_are_available_only_to_manager(): void
    {
        $customer = $this->makeUser(
            $this->customerOrganization,
            'customer',
            'Иван Заказчик',
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Реестр изменений доступен только УК');

        $this->service()->records($customer);
    }

    public function test_records_combine_catalog_and_request_events_in_date_order(): void
    {
        $request = $this->makeRepairRequest(
            $this->tenant,
            $this->customerOrganization,
            $this->manager,
        );
        $catalogEvent = $this->makeCatalogEvent(
            $this->tenant,
            $this->manager,
            CarbonImmutable::parse('2026-09-29 10:00:00'),
        );
        $requestEvent = RequestEvent::query()->create([
            'repair_request_id' => $request->id,
            'actor_id'          => null,
            'actor_label'       => 'Системный процесс',
            'action'            => 'next',
            'comment'           => 'Передано далее по маршруту',
            'stage'             => 1,
            'revision'          => 1,
            'details'           => [],
            'occurred_at'       => CarbonImmutable::parse('2026-09-29 11:00:00'),
        ]);

        $records = $this->service()->records($this->manager);

        $this->assertCount(2, $records);
        $this->assertSame('request-' . $requestEvent->uuid, $records[0]['id']);
        $this->assertSame('Заявка на ремонт', $records[0]['scope']);
        $this->assertSame($request->number, $records[0]['record']);
        $this->assertSame('Системный процесс', $records[0]['actor']);
        $this->assertSame('catalog-' . $catalogEvent->uuid, $records[1]['id']);
        $this->assertSame('Справочник: workTypes', $records[1]['scope']);
        $this->assertSame('name, active', $records[1]['changedFields']);
        $this->assertSame('Мария Менеджер', $records[1]['actor']);
        $this->assertSame(['canEdit' => false], $records[1]['permissions']);
    }

    public function test_records_do_not_include_another_tenants_events(): void
    {
        $this->makeCatalogEvent(
            $this->tenant,
            $this->manager,
            CarbonImmutable::parse('2026-09-29 10:00:00'),
        );

        $foreignTenant = Tenant::query()->create(['name' => 'Другая УК']);
        $foreignManagerOrganization = $this->makeOrganization(
            $foreignTenant,
            'manager',
            'ООО «Другая УК»',
        );
        $foreignCustomerOrganization = $this->makeOrganization(
            $foreignTenant,
            'customer',
            'ООО «Другой Заказчик»',
        );
        $foreignManager = $this->makeUser(
            $foreignManagerOrganization,
            'manager',
            'Другой Менеджер',
        );
        $foreignRequest = $this->makeRepairRequest(
            $foreignTenant,
            $foreignCustomerOrganization,
            $foreignManager,
        );
        $this->makeCatalogEvent(
            $foreignTenant,
            $foreignManager,
            CarbonImmutable::parse('2026-09-29 12:00:00'),
        );
        RequestEvent::query()->create([
            'repair_request_id' => $foreignRequest->id,
            'actor_id'          => $foreignManager->id,
            'actor_label'       => $foreignManager->display_name,
            'action'            => 'update',
            'comment'           => 'Чужое событие',
            'stage'             => 1,
            'revision'          => 1,
            'details'           => [],
            'occurred_at'       => CarbonImmutable::parse('2026-09-29 13:00:00'),
        ]);

        $records = $this->service()->records($this->manager);

        $this->assertCount(1, $records);
        $this->assertSame('Диагностика', $records[0]['record']);
    }

    public function test_catalog_record_without_actor_is_attributed_to_console(): void
    {
        $event = $this->makeCatalogEvent(
            $this->tenant,
            null,
            CarbonImmutable::parse('2026-09-29 10:00:00'),
        );

        $records = $this->service()->records($this->manager);

        $this->assertCount(1, $records);
        $this->assertSame('catalog-' . $event->uuid, $records[0]['id']);
        $this->assertSame('Консоль', $records[0]['actor']);
    }

    private function service(): ChangeRegistryService
    {
        return app(ChangeRegistryService::class);
    }

    private function makeOrganization(
        Tenant $tenant,
        string $kind,
        string $name,
    ): Organization {
        return Organization::query()->create([
            'tenant_id' => $tenant->id,
            'kind'      => $kind,
            'name'      => $name,
        ]);
    }

    private function makeUser(
        Organization $organization,
        string $role,
        string $displayName,
    ): User {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $role . '-' . Str::random(12),
            'display_name'    => $displayName,
            'role'            => $role,
            'password'        => 'test-password-long',
            'active'          => true,
        ]);
    }

    private function makeRepairRequest(
        Tenant $tenant,
        Organization $customer,
        User $creator,
    ): RepairRequest {
        $request = RepairRequest::query()->create([
            'public_id'                => (string) Str::uuid(),
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $customer->id,
            'created_by'               => $creator->id,
            'revision'                 => 0,
            'stage'                    => 1,
            'data'                     => (object) [],
            'works'                    => [],
            'history'                  => [],
        ]);

        return $request->refresh();
    }

    private function makeCatalogEvent(
        Tenant $tenant,
        ?User $actor,
        CarbonImmutable $occurredAt,
    ): CatalogChangeEvent {
        return CatalogChangeEvent::query()->create([
            'tenant_id'      => $tenant->id,
            'actor_id'       => $actor?->id,
            'catalog_kind'   => 'workTypes',
            'record_uuid'    => (string) Str::uuid(),
            'record_label'   => 'Диагностика',
            'action'         => 'update',
            'before_payload' => ['name' => 'Старая диагностика'],
            'after_payload'  => ['name' => 'Диагностика', 'active' => true],
            'changed_fields' => ['name', 'active'],
            'occurred_at'    => $occurredAt,
        ]);
    }
}
