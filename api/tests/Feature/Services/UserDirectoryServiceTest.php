<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserDirectoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Illuminate\Validation\ValidationException;

/**
 * Feature-тест для UserDirectoryService.
 *
 * Покрывает:
 *   - records: 403 для не-менеджера, список для менеджера
 *   - present: формат ответа с маской пароля, ФИО, организацией
 *   - create: успешное создание, валидация, 403
 *   - update: обновление ФИО, пароля, отзыв доступа
 *   - каскадные эффекты: удаление токенов, освобождение блокировок
 *   - 404 при попытке обновить чужого пользователя
 */
final class UserDirectoryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $managerOrg;
    private Organization $customerOrg;
    private User $manager;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-25 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->managerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'manager',
            'name'      => 'УК',
        ]);
        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);

        $this->manager = $this->makeUser(
            $this->managerOrg,
            'manager',
            'manager-login',
        );
        $this->customer = $this->makeUser(
            $this->customerOrg,
            'customer',
            'customer-login',
        );
    }

    /* ─────────────────── records ─────────────────── */

    public function test_records_returns_all_users_of_tenant_for_manager(): void
    {
        $this->makeUser($this->customerOrg, 'customer', 'another-customer');

        $records = $this->service()->records($this->manager);

        $logins = array_column($records, 'login');
        $this->assertContains('manager-login', $logins);
        $this->assertContains('customer-login', $logins);
        $this->assertContains('another-customer', $logins);
    }

    public function test_records_forbidden_for_customer(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Справочник пользователей доступен только УК');

        $this->service()->records($this->customer);
    }

    public function test_records_does_not_include_users_from_other_tenants(): void
    {
        $otherTenant = Tenant::query()->create(['name' => 'Другой контур']);
        $otherOrg = Organization::query()->create([
            'tenant_id' => $otherTenant->id,
            'kind'      => 'customer',
            'name'      => 'Другой заказчик',
        ]);
        $otherUser = $this->makeUser($otherOrg, 'customer', 'foreign-login');

        $records = $this->service()->records($this->manager);

        $logins = array_column($records, 'login');
        $this->assertNotContains('foreign-login', $logins);
    }

    /* ─────────────────── present ─────────────────── */

    public function test_present_includes_full_data_and_masks_password(): void
    {
        $presented = $this->service()->present($this->customer);

        $this->assertSame($this->customer->uuid, $presented['id']);
        $this->assertSame('users', $presented['kind']);
        $this->assertSame('customer-login', $presented['login']);
        $this->assertSame(UserDirectoryService::PASSWORD_MASK, $presented['password']);
        $this->assertSame('ООО «Заказчик»', $presented['organization']);
        $this->assertSame('customer', $presented['organizationKind']);
        $this->assertSame('Заказчик', $presented['organizationType']);
        $this->assertTrue($presented['hasAccess']);
        $this->assertContains('requests.view', $presented['accessRights']);
    }

    public function test_present_shows_access_revoked(): void
    {
        $this->customer->update([
            'active' => false,
            'access_revoked_at' => now(),
        ]);

        $presented = $this->service()->present($this->customer->refresh());

        $this->assertFalse($presented['hasAccess']);
        $this->assertNotNull($presented['accessRevokedAt']);
    }

    /* ─────────────────── create ─────────────────── */

    public function test_create_persists_user_with_hashed_password(): void
    {
        $record = $this->service()->create($this->manager, [
            'lastName'        => 'Иванов',
            'firstName'       => 'Иван',
            'middleName'      => 'Иванович',
            'email'           => 'ivan@example.test',
            'login'           => 'customer-user',
            'password'        => 'customer-password-long',
            'phone'           => '+7 (999) 111 22 33',
            'organizationId'  => $this->customerOrg->uuid,
            'registeredAt'    => '2026-09-25',
            'accessRevokedAt' => null,
            'active'          => true,
            'accessRights'    => ['requests.view', 'requests.create'],
        ]);

        $this->assertSame('customer-user', $record['login']);
        $this->assertSame('Иванов Иван Иванович', $record['fullName']);
        $this->assertSame('+7 (999) 111 22 33', $record['phone']);
        $this->assertSame(UserDirectoryService::PASSWORD_MASK, $record['password']);

        $created = User::query()->where('login', 'customer-user')->firstOrFail();
        $this->assertTrue(Hash::check('customer-password-long', $created->password));
        $this->assertSame($this->customerOrg->id, $created->organization_id);
        $this->assertSame(UserRole::Customer, $created->role);
        $this->assertSame(['requests.view', 'requests.create'], $created->access_rights);
    }

    public function test_create_creates_audit_event(): void
    {
        $this->service()->create($this->manager, $this->validPayload());

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'users')
            ->where('action', 'create')
            ->count());
    }

    public function test_create_rejects_duplicate_login(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create($this->manager, $this->validPayload([
            'login' => 'customer-login',  // уже занят
        ]));
    }

    public function test_create_rejects_organization_from_another_tenant(): void
    {
        $otherTenant = Tenant::query()->create(['name' => 'Другой']);
        $otherOrg = Organization::query()->create([
            'tenant_id' => $otherTenant->id,
            'kind'      => 'customer',
            'name'      => 'Другой',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service()->create($this->manager, $this->validPayload([
            'organizationId' => $otherOrg->uuid,
        ]));
    }

    public function test_create_rejects_invalid_phone(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Телефон должен быть указан в формате +7 (ххх) ххх хх хх');

        $this->service()->create($this->manager, $this->validPayload([
            'phone' => '+7 (999) 12',
        ]));
    }

    public function test_create_rejects_short_password(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create($this->manager, $this->validPayload([
            'password' => 'short',
        ]));
    }

    public function test_create_rejects_unknown_access_right(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create($this->manager, $this->validPayload([
            'accessRights' => ['unknown.right'],
        ]));
    }

    public function test_create_forbidden_for_customer(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Справочник пользователей доступен только УК');

        $this->service()->create($this->customer, $this->validPayload());
    }

    /* ─────────────────── update ─────────────────── */

    public function test_update_changes_name_and_contact_fields(): void
    {
        $record = $this->service()->update($this->manager, $this->customer, [
            'lastName'  => 'Петров',
            'firstName' => 'Пётр',
            'middleName' => 'Петрович',
            'email'     => 'petr@example.test',
            'phone'     => '+7 (999) 555 66 77',
        ]);

        $this->assertSame('Петров Пётр Петрович', $record['fullName']);
        $this->assertSame('petr@example.test', $record['email']);

        $this->customer->refresh();
        $this->assertSame('Петров', $this->customer->last_name);
        $this->assertSame('+79995556677', $this->customer->phone);
    }

    public function test_update_ignores_password_mask(): void
    {
        $before = $this->customer->password;

        $this->service()->update($this->manager, $this->customer, [
            'password' => UserDirectoryService::PASSWORD_MASK,
        ]);

        $this->assertSame($before, $this->customer->refresh()->password);
    }

    public function test_update_changes_password_and_revokes_tokens(): void
    {
        $token = $this->customer->createToken('api')->plainTextToken;
        $this->assertSame(1, $this->customer->tokens()->count());

        $this->service()->update($this->manager, $this->customer, [
            'password' => 'new-password-long-value',
        ]);

        $this->assertSame(0, $this->customer->refresh()->tokens()->count());
        $this->assertTrue(Hash::check(
            'new-password-long-value',
            $this->customer->password,
        ));
    }

    public function test_update_revokes_access_and_tokens(): void
    {
        $this->customer->createToken('api');
        $this->assertSame(1, $this->customer->tokens()->count());

        $this->service()->update($this->manager, $this->customer, [
            'accessRevokedAt' => '2026-09-25',
        ]);

        $this->customer->refresh();
        $this->assertNotNull($this->customer->access_revoked_at);
        $this->assertFalse($this->customer->hasPlatformAccess());
        $this->assertSame(0, $this->customer->tokens()->count());
    }

    public function test_update_deactivates_user_and_revokes_tokens(): void
    {
        $this->customer->createToken('api');

        $this->service()->update($this->manager, $this->customer, [
            'active' => false,
        ]);

        $this->assertFalse($this->customer->refresh()->active);
        $this->assertSame(0, $this->customer->tokens()->count());
    }

    public function test_update_changes_organization_and_revokes_tokens(): void
    {
        $this->customer->createToken('api');

        $newOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'Новая организация',
        ]);

        $this->service()->update($this->manager, $this->customer, [
            'organizationId' => $newOrg->uuid,
        ]);

        $this->assertSame($newOrg->id, $this->customer->refresh()->organization_id);
        $this->assertSame(0, $this->customer->tokens()->count());
    }

    public function test_update_returns_404_for_user_from_another_tenant(): void
    {
        $otherTenant = Tenant::query()->create(['name' => 'Другой']);
        $otherOrg = Organization::query()->create([
            'tenant_id' => $otherTenant->id,
            'kind'      => 'customer',
            'name'      => 'Другой',
        ]);
        $otherUser = $this->makeUser($otherOrg, 'customer', 'other-login');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->service()->update($this->manager, $otherUser, [
            'lastName' => 'Смит',
        ]);
    }

    public function test_update_creates_audit_event(): void
    {
        $this->service()->update($this->manager, $this->customer, [
            'lastName' => 'Петров',
        ]);

        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'users')
            ->where('action', 'update')
            ->count());
    }

    public function test_update_forbidden_for_customer(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->update($this->customer, $this->customer, [
            'lastName' => 'Петров',
        ]);
    }

    /* ─────────────────── статические методы ─────────────────── */

    public function test_default_rights_for_each_role(): void
    {
        $this->assertContains('users.manage', UserDirectoryService::defaultRights('manager'));
        $this->assertContains('requests.station', UserDirectoryService::defaultRights('station'));
        $this->assertContains('requests.create', UserDirectoryService::defaultRights('customer'));
    }

    public function test_right_options_returns_all_with_labels(): void
    {
        $options = UserDirectoryService::rightOptions();

        $this->assertCount(count(UserDirectoryService::ACCESS_RIGHTS), $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertContains($option['value'], UserDirectoryService::ACCESS_RIGHTS);
        }
    }

    /* ─────────────────── helpers ─────────────────── */

    private function service(): UserDirectoryService
    {
        return app(UserDirectoryService::class);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'lastName'        => 'Тестов',
            'firstName'       => 'Тест',
            'middleName'      => 'Тестович',
            'email'           => 'test-' . Str::random(6) . '@example.test',
            'login'           => 'user-' . Str::random(6),
            'password'        => 'test-password-long',
            'phone'           => '+7 (999) 111 22 33',
            'organizationId'  => $this->customerOrg->uuid,
            'registeredAt'    => '2026-09-25',
            'accessRevokedAt' => null,
            'active'          => true,
            'accessRights'    => ['requests.view'],
        ], $overrides);
    }

    private function makeUser(
        Organization $organization,
        string $role,
        string $login,
    ): User {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $login,
            'display_name'    => ucfirst($role),
            'role'            => $role,
            'password'        => 'test-password-long',
            'active'          => true,
            'last_name'       => 'Тестов',
            'first_name'      => 'Тест',
            'email'           => $login . '@example.test',
            'phone'           => '+79991112233',
            'access_rights'   => ['requests.view'],
        ]);
    }
}
