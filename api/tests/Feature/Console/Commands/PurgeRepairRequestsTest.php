<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class PurgeRepairRequestsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_removes_requests_and_restarts_numbering(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        $customerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
        $customer = User::query()->create([
            'organization_id' => $customerOrg->id,
            'login'           => 'customer-purge',
            'display_name'    => 'Заказчик',
            'role'            => 'customer',
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        $this->actingAs($customer);

        $this->postJsonObject('/api/requests')->assertCreated();
        $this->assertSame(1, RepairRequest::query()->count());

        $this->artisan('sto:requests-purge', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, RepairRequest::query()->count());

        // После purge команда сбрасывает sequence — номер должен начаться заново
        $second = $this->postJsonObject('/api/requests')
            ->assertCreated()
            ->json('request');

        $this->assertSame('RP-000001', $second['number']);
    }

    public function test_cancels_without_force_when_requests_exist(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        $customerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
        $customer = User::query()->create([
            'organization_id' => $customerOrg->id,
            'login'           => 'customer-purge-2',
            'display_name'    => 'Заказчик',
            'role'            => 'customer',
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        $this->actingAs($customer);
        $this->postJsonObject('/api/requests')->assertCreated();

        // --force не передан, confirm отменяется (по умолчанию false)
        $this->artisan('sto:requests-purge')
            ->expectsConfirmation(
            'Безвозвратно удалить все заявки (1) и зависимые документы?',
            'no',
        )
            ->assertFailed();

        $this->assertSame(1, RepairRequest::query()->count());
    }
}