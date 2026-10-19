<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\DocumentLock;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature-тест для DocumentLockController.
 *
 * Покрывает HTTP-контракт поверх DocumentLockService:
 *   - POST   /api/document-locks              → 201 {lock:{...}}
 *   - POST   /api/document-locks/{id}/heartbeat → 200 {lock:{...}}
 *   - DELETE /api/document-locks/{id}         → 204
 *
 * Коды: 401 (без авторизации), 403 (station + catalog_record),
 * 404 (unknown / чужой lock в heartbeat), 422 (валидация, лишние поля),
 * 423 (чужой активный lock).
 *
 * Бизнес-логика сервиса отдельно покрыта DocumentLockServiceTest —
 * здесь проверяем именно маппинг на HTTP.
 */
final class DocumentLockControllerTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $manager;
    private User $otherManager;
    private User $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

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

        $this->manager      = $this->makeUser($this->managerOrg, 'manager');
        $this->otherManager = $this->makeUser($this->managerOrg, 'manager');
        $this->station      = $this->makeUser($this->stationOrg, 'station');
    }

    /* ─────────────────── acquire ─────────────────── */

    public function test_acquire_creates_lock_and_returns_201(): void
    {
        $this->actingAs($this->manager);

        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
            'label'        => 'Работа',
        ])
            ->assertCreated()
            ->assertJsonPath('lock.resourceType', 'catalog_record')
            ->assertJsonPath('lock.resourceKey', 'workTypes:abc-uuid')
            ->assertJsonPath('lock.label', 'Работа')
            ->assertJsonPath('lock.lockedLogin', $this->manager->login);

        $this->assertSame(1, DocumentLock::query()->count());
    }

    public function test_acquire_uses_key_as_label_when_label_missing(): void
    {
        $this->actingAs($this->manager);

        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'suppliers:abc-uuid',
        ])
            ->assertCreated()
            ->assertJsonPath('lock.label', 'suppliers:abc-uuid');
    }

    public function test_acquire_returns_423_for_other_users_lock(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->assertCreated();

        $this->actingAs($this->otherManager);
        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED')
            ->assertJsonPath('error.fields.lockedLogin', $this->manager->login);
    }

    public function test_acquire_replaces_expired_lock(): void
    {
        $this->actingAs($this->manager);
        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->assertCreated();

        DocumentLock::query()->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($this->otherManager);
        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])
            ->assertCreated()
            ->assertJsonPath('lock.lockedLogin', $this->otherManager->login);

        $this->assertSame(1, DocumentLock::query()->count());
    }

    public function test_acquire_rejects_station_for_catalog_record_with_403(): void
    {
        $this->actingAs($this->station);

        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_acquire_rejects_catalog_record_without_colon_with_422(): void
    {
        $this->actingAs($this->manager);

        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'invalid-key',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_acquire_rejects_unknown_fields_with_422(): void
    {
        $this->actingAs($this->manager);

        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
            'extraField'   => 'nope',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_acquire_rejects_empty_body_with_422(): void
    {
        $this->actingAs($this->manager);

        $this->postJson('/api/document-locks', [])
            ->assertUnprocessable();
    }

    /* ─────────────────── heartbeat ─────────────────── */

    public function test_heartbeat_returns_200_and_extends_lock(): void
    {
        $this->actingAs($this->manager);
        $lock = $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->json('lock');

        $this->travel(60)->seconds();

        $this->postJsonObject('/api/document-locks/' . $lock['id'] . '/heartbeat')
            ->assertOk()
            ->assertJsonPath('lock.id', $lock['id']);
    }

    public function test_heartbeat_returns_404_for_unknown_lock(): void
    {
        $this->actingAs($this->manager);

        $this->postJsonObject(
            '/api/document-locks/' . Str::uuid() . '/heartbeat',
        )->assertNotFound();
    }

    public function test_heartbeat_returns_404_for_other_users_lock(): void
    {
        $this->actingAs($this->manager);
        $lock = $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->json('lock');

        $this->actingAs($this->otherManager);
        $this->postJsonObject('/api/document-locks/' . $lock['id'] . '/heartbeat')
            ->assertNotFound();
    }

    public function test_heartbeat_rejects_unknown_fields_with_422(): void
    {
        $this->actingAs($this->manager);
        $lock = $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->json('lock');

        $this->postJson('/api/document-locks/' . $lock['id'] . '/heartbeat', [
            'something' => true,
        ])->assertUnprocessable();
    }

    /* ─────────────────── release ─────────────────── */

    public function test_release_returns_204_and_removes_lock(): void
    {
        $this->actingAs($this->manager);
        $lock = $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->json('lock');

        $this->deleteJson('/api/document-locks/' . $lock['id'])
            ->assertNoContent();

        $this->assertSame(0, DocumentLock::query()->count());
    }

    public function test_release_returns_204_for_unknown_lock(): void
    {
        $this->actingAs($this->manager);

        $this->deleteJson('/api/document-locks/' . Str::uuid())
            ->assertNoContent();
    }

    public function test_release_does_not_touch_other_users_lock(): void
    {
        $this->actingAs($this->manager);
        $lock = $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->json('lock');

        $this->actingAs($this->otherManager);
        $this->deleteJson('/api/document-locks/' . $lock['id'])
            ->assertNoContent();

        $this->assertSame(1, DocumentLock::query()->count());
    }

    /* ─────────────────── auth ─────────────────── */

    public function test_acquire_requires_authentication(): void
    {
        $this->postJson('/api/document-locks', [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ])->assertUnauthorized();
    }

    /* ─────────────────── helpers ─────────────────── */

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
