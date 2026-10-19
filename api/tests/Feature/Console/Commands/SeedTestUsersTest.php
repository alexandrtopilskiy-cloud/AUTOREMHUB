<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\SeedTestUsers;
use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SeedTestUsersTest extends TestCase
{
    use DatabaseTransactions;

    private function tenantWithManager(): Tenant
    {
        $tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'kind'      => OrganizationKind::Manager,
            'name'      => 'Тестовая УК',
            'contact'   => '',
        ]);

        return $tenant;
    }

    public function test_creates_three_users_per_cabinet_with_shared_password(): void
    {
        $tenant = $this->tenantWithManager();

        $this->artisan('sto:test-users-create', ['--tenant' => $tenant->id])->assertSuccessful();

        $expected = [
            'UK'     => 'manager', 'UK1' => 'manager', 'UK2' => 'manager',
            'Zakaz'  => 'customer', 'Zakaz1' => 'customer', 'Zakaz2' => 'customer',
            'STO'    => 'station', 'STO1' => 'station', 'STO2' => 'station',
        ];

        foreach ($expected as $login => $role) {
            $user = User::query()->where('login', $login)->firstOrFail();
            $this->assertSame($role, $user->role->value, $login);
            $this->assertSame($tenant->id, $user->organization->tenant_id, $login);
            $this->assertTrue($user->active, $login);
            $this->assertTrue(Hash::check(SeedTestUsers::PASSWORD, $user->password), $login);
        }

        $this->postJson('/api/login', [
            'login'    => 'Zakaz1',
            'password' => SeedTestUsers::PASSWORD,
        ], ['Origin' => 'http://localhost:5173'])->assertOk();
    }

    public function test_is_idempotent_and_keeps_existing_password_without_reset(): void
    {
        $tenant = $this->tenantWithManager();
        $this->artisan('sto:test-users-create', ['--tenant' => $tenant->id])->assertSuccessful();
        User::query()->where('login', 'STO')->firstOrFail()->forceFill(['password' => 'Other-password-1'])->save();

        $this->artisan('sto:test-users-create', ['--tenant' => $tenant->id])->assertSuccessful();
        $this->assertSame(9, User::query()->whereIn('login', array_column(SeedTestUsers::USERS, 'login'))->count());
        $this->assertTrue(Hash::check('Other-password-1', User::query()->where('login', 'STO')->firstOrFail()->password));

        $this->artisan('sto:test-users-create', ['--tenant' => $tenant->id, '--reset-password' => true])->assertSuccessful();
        $this->assertTrue(Hash::check(SeedTestUsers::PASSWORD, User::query()->where('login', 'STO')->firstOrFail()->password));
    }

    public function test_fails_without_manager_organization(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Без УК']);

        $this->artisan('sto:test-users-create', ['--tenant' => $tenant->id])->assertFailed();
    }
}
