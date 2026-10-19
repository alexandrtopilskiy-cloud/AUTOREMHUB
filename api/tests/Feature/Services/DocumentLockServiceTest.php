<?php
declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\DocumentLock;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentLockService;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Feature-тест для DocumentLockService.
 *
 * Покрывает:
 *   - acquire: создание, продление (idempotent для того же user),
 *     истечение старого lock, чужой lock → 423, station для catalog_record → 403,
 *     request без прав → 403 READ_ONLY
 *   - heartbeat: только свой lock
 *   - release / releaseAll / releaseResource
 *   - assertAvailable: активный чужой → 423, истёкший → ок
 */
final class DocumentLockServiceTest extends TestCase
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

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));

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
    }

    /* ─────────────────── acquire: catalog_record ─────────────────── */

    public function test_acquire_creates_lock_for_catalog_record(): void
    {
        $result = $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
            'label'        => 'Работа',
        ]);

        $this->assertSame('catalog_record', $result['resourceType']);
        $this->assertSame('workTypes:abc-uuid', $result['resourceKey']);
        $this->assertSame('Работа', $result['label']);
        $this->assertSame($this->manager->login, $result['lockedLogin']);

        $this->assertSame(1, DocumentLock::query()->count());
    }

    public function test_acquire_uses_key_as_label_when_label_empty(): void
    {
        $result = $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'suppliers:abc-uuid',
        ]);

        $this->assertSame('suppliers:abc-uuid', $result['label']);
    }

    public function test_acquire_is_idempotent_for_same_user(): void
    {
        $payload = [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
            'label'        => 'Работа',
        ];

        $this->service()->acquire($this->manager, $payload);
        $this->travel(60)->seconds();
        $second = $this->service()->acquire($this->manager, $payload);

        $this->assertSame(1, DocumentLock::query()->count());
        $this->assertSame('Работа', $second['label']);
    }

    public function test_acquire_rejects_other_user(): void
    {
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $other = $this->makeUser($this->managerOrg, 'manager');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Документ уже открыт пользователем');

        $this->service()->acquire($other, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);
    }

    public function test_acquire_replaces_expired_lock(): void
    {
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        // Просрочим lock на 10 минут.
        DocumentLock::query()->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $other = $this->makeUser($this->managerOrg, 'manager');
        $result = $this->service()->acquire($other, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $this->assertSame($other->login, $result['lockedLogin']);
        $this->assertSame(1, DocumentLock::query()->count());
    }

    public function test_acquire_for_catalog_record_is_forbidden_for_station(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Справочники недоступны кабинету СТО');

        $this->service()->acquire($this->station, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);
    }

    public function test_acquire_requires_colon_in_catalog_record_key(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Укажите вид и UUID записи справочника');

        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'invalid-key',
        ]);
    }

    public function test_acquire_throws_validation_on_invalid_resource_type(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->acquire($this->manager, [
            'resourceType' => 'invalid',
            'resourceKey'  => 'x',
        ]);
    }

    /* ─────────────────── heartbeat ─────────────────── */

    public function test_heartbeat_extends_own_lock(): void
    {
        $lock = $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $this->travel(60)->seconds();
        $result = $this->service()->heartbeat($this->manager, $lock['id']);

        $this->assertSame($lock['id'], $result['id']);
        $this->assertGreaterThan(now()->timestamp, strtotime($result['expiresAt']));
    }

    public function test_heartbeat_fails_for_other_user(): void
    {
        $lock = $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $other = $this->makeUser($this->managerOrg, 'manager');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service()->heartbeat($other, $lock['id']);
    }

    /* ─────────────────── release ─────────────────── */

    public function test_release_removes_own_lock(): void
    {
        $lock = $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $this->service()->release($this->manager, $lock['id']);

        $this->assertSame(0, DocumentLock::query()->count());
    }

    public function test_release_does_not_touch_other_lock(): void
    {
        $lock = $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $other = $this->makeUser($this->managerOrg, 'manager');
        $this->service()->release($other, $lock['id']);

        $this->assertSame(1, DocumentLock::query()->count());
    }

    public function test_release_all_removes_every_lock_of_user(): void
    {
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'parts:def-uuid',
        ]);

        $this->service()->releaseAll($this->manager);

        $this->assertSame(0, DocumentLock::query()->count());
    }

    public function test_release_resource_removes_by_type_and_key(): void
    {
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'parts:def-uuid',
        ]);

        $this->service()->releaseResource(
            $this->manager,
            'catalog_record',
            'workTypes:abc-uuid',
        );

        $this->assertSame(1, DocumentLock::query()->count());
        $this->assertSame(
            'parts:def-uuid',
            DocumentLock::query()->first()->resource_key,
        );
    }

    /* ─────────────────── assertAvailable ─────────────────── */

    public function test_assert_available_passes_when_no_lock(): void
    {
        $this->service()->assertAvailable(
            $this->manager,
            'catalog_record',
            'workTypes:abc-uuid',
        );

        $this->addToAssertionCount(1);
    }

    public function test_assert_available_passes_for_own_lock(): void
    {
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $this->service()->assertAvailable(
            $this->manager,
            'catalog_record',
            'workTypes:abc-uuid',
        );

        $this->addToAssertionCount(1);
    }

    public function test_assert_available_throws_423_for_other_lock(): void
    {
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        $other = $this->makeUser($this->managerOrg, 'manager');

        try {
            $this->service()->assertAvailable(
                $other,
                'catalog_record',
                'workTypes:abc-uuid',
            );
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(423, $e->status);
            $this->assertSame('DOCUMENT_LOCKED', $e->errorCode);
            $this->assertSame($this->manager->login, $e->fields['lockedLogin']);
        }
    }

    public function test_assert_available_passes_for_expired_lock(): void
    {
        $this->service()->acquire($this->manager, [
            'resourceType' => 'catalog_record',
            'resourceKey'  => 'workTypes:abc-uuid',
        ]);

        DocumentLock::query()->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $other = $this->makeUser($this->managerOrg, 'manager');
        $this->service()->assertAvailable(
            $other,
            'catalog_record',
            'workTypes:abc-uuid',
        );

        $this->addToAssertionCount(1);
    }

    /* ─────────────────── resolveResource: request ─────────────────── */

    public function test_acquire_request_fails_for_read_only_request(): void
    {
        // Заявка в статусе Draft, customer — владелец, но stage=0 и created_by
        // другого пользователя → нет прав.
        $request = $this->makeRequest($this->customerOrg, stage: 1, createdBy: $this->manager->id);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Заявка доступна в этом кабинете только для просмотра');

        // customer пытается заблокировать чужой черновик на stage=1 —
        // это read-only для него.
        $this->service()->acquire($this->customer, [
            'resourceType' => 'request',
            'resourceKey'  => $request->uuid,
        ]);
    }

    /* ─────────────────── helpers ─────────────────── */

    private function service(): DocumentLockService
    {
        return app(DocumentLockService::class);
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

    private function makeRequest(
        Organization $customerOrg,
        int $stage = 0,
        ?int $createdBy = null,
    ): RepairRequest {
        $uuid = (string) Str::uuid();

        return RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $customerOrg->id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => $stage,
            'revision'                 => 1,
            'created_by'               => $createdBy ?? $this->customer->id,
        ]);
    }
}
