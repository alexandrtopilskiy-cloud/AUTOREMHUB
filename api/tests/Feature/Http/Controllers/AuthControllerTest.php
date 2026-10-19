<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\DocumentLock;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Illuminate\Testing\TestResponse;

/**
 * Feature-тест для AuthController.
 *
 * Покрывает:
 *   - POST /api/login   (happy, ошибки, force, rate limit, активные сессии)
 *   - GET  /api/session (happy, 401)
 *   - POST /api/logout  (204, удаление токена, releaseAll)
 *
 * Критично: тесты покрывают RateLimiter::clear через фасад,
 * который ломал прод (нестатический вызов).
 */
final class AuthControllerTest extends TestCase
{
    use DatabaseTransactions;

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

        $this->customer = $this->makeUser($this->customerOrg, 'customer', 'customer-login');
        $this->manager  = $this->makeUser($this->managerOrg, 'manager', 'manager-login');
        $this->station  = $this->makeUser($this->stationOrg, 'station', 'station-login');
    }

    /* ─────────────────── login: happy path ─────────────────── */

    public function test_login_returns_token_and_user_data(): void
    {
        $response = $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.login', 'customer-login')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('user.label', 'Заказчик')
            ->assertJsonStructure(['user' => ['id', 'systemId', 'role'], 'token']);

        $this->assertIsString($response->json('token'));
        $this->assertNotEmpty($response->json('token'));

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_returns_customer_permissions(): void
    {
        $response = $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertOk();

        $this->assertTrue(
            $response->json('user.permissions.canCreateRequest'),
        );
    }

    public function test_login_returns_manager_permissions(): void
    {
        $response = $this->postLogin([
            'login'    => 'manager-login',
            'password' => 'test-password-long',
        ])->assertOk();

        $this->assertFalse(
            $response->json('user.permissions.canCreateRequest'),
        );
    }

    /* ─────────────────── login: ошибки ─────────────────── */

    public function test_login_rejects_unknown_login(): void
    {
        $this->postLogin([
            'login'    => 'nonexistent',
            'password' => 'test-password-long',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_rejects_inactive_user(): void
    {
        $this->customer->update(['active' => false]);

        $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertUnauthorized();
    }

    public function test_login_rejects_user_with_mismatched_role_and_organization_kind(): void
    {
        // Роль manager, но организация — customer. hasValidRole() = false.
        $this->customer->update(['role' => 'manager']);

        $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertUnauthorized();
    }

    public function test_login_rejects_missing_credentials(): void
    {
        $this->postLogin([])
            ->assertUnprocessable();
    }

    /* ─────────────────── login: активные токены ─────────────────── */

    public function test_login_rejects_second_session_without_force(): void
    {
        // Первый логин
        $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertOk();

        // Второй логин без force — должен получить 409
        $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_ACTIVE');
    }

    public function test_login_with_force_deletes_old_tokens(): void
    {
        $first = $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertOk()->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $second = $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
            'force'    => true,
        ])->assertOk()->json('token');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // Первый токен удалён — попытка аутентификации с ним даёт 401.
        $this->withHeader('Authorization', 'Bearer ' . $first)
            ->getJson('/api/session')
            ->assertUnauthorized();
    }

    public function test_login_with_force_releases_document_locks(): void
    {
        // Создаём токен и локи
        $this->actingAs($this->customer);
        DocumentLock::query()->create([
            'tenant_id'      => $this->tenant->id,
            'user_id'        => $this->customer->id,
            'resource_type'  => 'catalog_record',
            'resource_key'   => 'workTypes:abc',
            'resource_label' => 'Работа',
            'expires_at'     => now()->addMinutes(5),
        ]);
        $this->assertDatabaseCount('document_locks', 1);

        // Логин с force — локи должны быть удалены
        $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
            'force'    => true,
        ])->assertOk();

        $this->assertDatabaseCount('document_locks', 0);
    }

    /* ─────────────────── login: rate limit ─────────────────── */

    public function test_login_rate_limit_blocks_after_five_attempts(): void
    {
        // Сбрасываем счётчики для чистого старта
        $limiter = app(\Illuminate\Cache\RateLimiter::class);
        $limiter->clear('login-ip:127.0.0.1');
        $limiter->clear('login:' . hash('sha256', 'rate-limited-user|127.0.0.1'));

        // 5 неудачных попыток — все 401
        for ($i = 0; $i < 5; $i++) {
            $this->postLogin([
                'login'    => 'rate-limited-user',
                'password' => 'wrong',
            ])->assertUnauthorized();
        }

        // 6-я попытка — 429 (сработал лимит по IP+login)
        $this->postLogin([
            'login'    => 'rate-limited-user',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_login_rate_limit_persists_after_successful_attempt(): void
    {
        // Чистим все счётчики через именованный префикс Laravel
        $limiter = app(\Illuminate\Cache\RateLimiter::class);

        $login = 'customer-login';
        $ip = '127.0.0.1';

        $prefix = 'login';  // имя лимитера
        $limiter->clear(md5($prefix . 'login-ip:' . $ip));
        $limiter->clear(md5($prefix . 'login:' . hash('sha256', $login . '|' . $ip)));
        $limiter->clear(md5($prefix . 'login-global:' . hash('sha256', $login)));

        // 5 неудачных — все 401
        for ($i = 0; $i < 5; $i++) {
            $this->postLogin(['login' => $login, 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        // Успешный логин — тоже 429 (лимит держится)
        $this->postLogin(['login' => $login, 'password' => 'test-password-long'])
            ->assertStatus(429);

        // Счётчики в БД/кеше не сброшены
        $this->assertGreaterThan(0, $limiter->attempts(md5($prefix . 'login:' . hash('sha256', $login . '|' . $ip))));
    }

    /* ─────────────────── session ─────────────────── */

    public function test_session_returns_user_data(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('user.login', 'customer-login')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure(['user' => ['id', 'systemId', 'organizationId', 'role']]);
    }

    public function test_session_requires_authentication(): void
    {
        $this->getJson('/api/session')->assertUnauthorized();
    }

    /* ─────────────────── logout ─────────────────── */

    public function test_logout_deletes_current_token(): void
    {
        $token = $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertOk()->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->postJsonObjectAs('/api/logout', $token)
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_releases_document_locks(): void
    {
        $token = $this->postLogin([
            'login'    => 'customer-login',
            'password' => 'test-password-long',
        ])->assertOk()->json('token');

        DocumentLock::query()->create([
            'tenant_id'      => $this->tenant->id,
            'user_id'        => $this->customer->id,
            'resource_type'  => 'catalog_record',
            'resource_key'   => 'workTypes:abc',
            'resource_label' => 'Работа',
            'expires_at'     => now()->addMinutes(5),
        ]);

        $this->postJsonObjectAs('/api/logout', $token)
            ->assertNoContent();

        $this->assertDatabaseCount('document_locks', 0);
    }

    /* ─────────────────── helpers ─────────────────── */

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
        ]);
    }

    /**
 * POST /api/login с заголовком Origin.
 *
 * Middleware EnsureLoginOrigin разрешает только FRONTEND_ORIGINS
 * и APP_URL. В тестах — http://localhost:5173 (из phpunit.xml).
 */
    private function postLogin(array $payload): TestResponse
    {
        return $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/login', $payload);
    }
}
