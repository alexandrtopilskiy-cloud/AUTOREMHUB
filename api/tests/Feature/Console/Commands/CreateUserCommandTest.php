<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CreateUserCommandTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
    }

    public function test_creates_user_with_all_fields(): void
    {
        $this->artisan('sto:create-user', [
            'login'        => 'test-login',
            'organization' => (string) $this->customerOrg->id,
            '--last-name'  => 'Иванов',
            '--first-name' => 'Иван',
            '--middle-name' => 'Иванович',
            '--email'      => 'ivan@example.test',
            '--phone'      => '+7 (999) 111 22 33',
        ])
            ->expectsQuestion('Пароль (от 12 до 72 байт)', 'test-password-long')
            ->expectsQuestion('Повторите пароль', 'test-password-long')
            ->assertSuccessful();

        $user = User::query()->where('login', 'test-login')->firstOrFail();

        $this->assertSame($this->customerOrg->id, $user->organization_id);
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame('Иванов', $user->last_name);
        $this->assertSame('+79991112233', $user->phone);
        $this->assertTrue(Hash::check('test-password-long', $user->password));
    }

    public function test_creates_user_by_uuid(): void
    {
        $this->artisan('sto:create-user', [
            'login'        => 'test-uuid',
            'organization' => $this->customerOrg->uuid,
            '--last-name'  => 'Петров',
            '--first-name' => 'Пётр',
            '--email'      => 'petr@example.test',
            '--phone'      => '+79991112233',
        ])
            ->expectsQuestion('Пароль (от 12 до 72 байт)', 'test-password-long')
            ->expectsQuestion('Повторите пароль', 'test-password-long')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['login' => 'test-uuid']);
    }

    public function test_fails_for_unknown_organization(): void
    {
        $this->artisan('sto:create-user', [
            'login'        => 'test-login',
            'organization' => '999999',
            '--last-name'  => 'Иванов',
            '--first-name' => 'Иван',
            '--email'      => 'ivan@example.test',
            '--phone'      => '+79991112233',
        ])->assertFailed();
    }
}
