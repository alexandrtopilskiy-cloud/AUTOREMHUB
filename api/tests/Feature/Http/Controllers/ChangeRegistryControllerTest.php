<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ChangeRegistryControllerTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private User $customer;
    private User $manager;

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

        $this->customer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager  = $this->makeUser($this->managerOrg, 'manager');
    }

    public function test_index_returns_records_for_manager(): void
    {
        CatalogChangeEvent::query()->create([
            'tenant_id'      => $this->tenant->id,
            'actor_id'       => $this->manager->id,
            'catalog_kind'   => 'workTypes',
            'record_uuid'    => Str::uuid(),
            'record_label'   => 'Диагностика',
            'action'         => 'create',
            'changed_fields' => ['name'],
            'occurred_at'    => now(),
        ]);

        $this->actingAs($this->manager);
        $this->getJson('/api/change-registry')
            ->assertOk()
            ->assertJsonStructure(['records'])
            ->assertJsonFragment(['actor' => $this->manager->display_name]);
    }

    public function test_index_forbidden_for_customer(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/change-registry')->assertForbidden();
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