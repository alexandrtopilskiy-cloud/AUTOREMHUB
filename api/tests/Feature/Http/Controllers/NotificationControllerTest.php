<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestNotification;
use App\Models\RequestStatusPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleChangeRequest;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для NotificationController.
 *
 * Покрывает:
 *   - index: пусто, только свои и только непрочитанные
 *   - read: прочитанное уведомление удаляется для любой роли, чужое → 404
 *   - present: targetType=vehicleApproval / request
 */
final class NotificationControllerTest extends TestCase
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

    /* ─────────────────── index ─────────────────── */

    public function test_index_returns_empty_initially(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications');
    }

    public function test_index_shows_only_own_notifications(): void
    {
        $this->makeNotification($this->customer, 'for-customer');
        $this->makeNotification($this->manager, 'for-manager');

        $this->actingAs($this->customer);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', 'for-customer');
    }

    public function test_index_customer_sees_only_unread(): void
    {
        $this->makeNotification($this->customer, 'unread');
        $this->makeNotification($this->customer, 'read', readAt: now()->subHour());

        $this->actingAs($this->customer);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', 'unread');
    }

    public function test_index_manager_sees_only_unread(): void
    {
        $this->makeNotification($this->manager, 'unread');
        $this->makeNotification($this->manager, 'read', readAt: now()->subHour());

        $this->actingAs($this->manager);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', 'unread');
    }

    public function test_index_station_sees_only_unread(): void
    {
        $this->makeNotification($this->station, 'unread');
        $this->makeNotification($this->station, 'read', readAt: now()->subHour());

        $this->actingAs($this->station);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', 'unread');
    }

    public function test_index_limits_to_100(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->makeNotification($this->manager, "n{$i}");
        }

        $this->actingAs($this->manager);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(100, 'notifications');
    }

    /* ─────────────────── read: удаление для любой роли ─────────────────── */

    public function test_read_customer_deletes_notification(): void
    {
        $notification = $this->makeNotification($this->customer, 'test');

        $this->actingAs($this->customer);
        $this->postRead($notification->uuid)
            ->assertOk()
            ->assertJsonPath('notification.deleted', true)
            ->assertJsonPath('notification.title', 'test');

        $this->assertDatabaseMissing('request_notifications', [
            'id' => $notification->id,
        ]);
    }

    public function test_read_customer_removes_from_list(): void
    {
        $notification = $this->makeNotification($this->customer, 'test');

        $this->actingAs($this->customer);
        $this->postRead($notification->uuid)
            ->assertOk();

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications');
    }

    public function test_read_manager_deletes_notification(): void
    {
        $notification = $this->makeNotification($this->manager, 'test');

        $this->actingAs($this->manager);
        $this->postRead($notification->uuid)
            ->assertOk()
            ->assertJsonPath('notification.deleted', true)
            ->assertJsonPath('notification.title', 'test');

        $this->assertDatabaseMissing('request_notifications', [
            'id' => $notification->id,
        ]);
    }

    public function test_read_manager_removes_from_list(): void
    {
        $notification = $this->makeNotification($this->manager, 'test');

        $this->actingAs($this->manager);
        $this->postRead($notification->uuid)
            ->assertOk();

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications');
    }

    /* ─────────────────── read: чужие / повторные ─────────────────── */

    public function test_read_other_users_notification_is_404(): void
    {
        $notification = $this->makeNotification($this->manager, 'for-manager');

        $this->actingAs($this->customer);
        $this->postRead($notification->uuid)
            ->assertNotFound();
    }

    public function test_read_unknown_uuid_is_404(): void
    {
        $this->actingAs($this->customer);
        $this->postRead((string)Str::uuid())
            ->assertNotFound();
    }

    public function test_read_twice_customer_is_404(): void
    {
        $notification = $this->makeNotification($this->customer, 'test');

        $this->actingAs($this->customer);
        $this->postRead($notification->uuid)
            ->assertOk();

        $this->postRead($notification->uuid)
            ->assertNotFound();
    }

    /* ─────────────────── present: targetType ─────────────────── */

    public function test_present_request_target_type(): void
    {
        $request = $this->makeRequest();
        $notification = $this->makeNotification(
            $this->customer,
            'test',
            repairRequestId: $request->id,
        );

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.targetType', 'request')
            ->assertJsonPath('notifications.0.requestId', $request->uuid);

        $this->assertSame(
            RepairRequest::query()->find($request->id)->number,
            $response->json('notifications.0.requestNumber'),
        );
    }

    public function test_present_vehicle_approval_target_type(): void
    {
        $change = $this->makeVehicleChangeRequest();
        $notification = $this->makeNotification(
            $this->manager,
            'test',
            vehicleChangeRequestId: $change->id,
        );

        $this->actingAs($this->manager);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.targetType', 'vehicleApproval')
            ->assertJsonPath('notifications.0.targetId', $change->uuid);
    }

    /* ─────────────────── helpers ─────────────────── */

    private function makeNotification(
        User $user,
        string $title,
        ?\Carbon\CarbonInterface $readAt = null,
        ?int $repairRequestId = null,
        ?int $vehicleChangeRequestId = null,
    ): RequestNotification {
        return RequestNotification::query()->create([
            'uuid'                      => (string) Str::uuid(),
            'user_id'                   => $user->id,
            'repair_request_id'         => $repairRequestId,
            'vehicle_change_request_id' => $vehicleChangeRequestId,
            'request_event_id'          => null,
            'kind'                      => 'work_started',
            'title'                     => $title,
            'message'                   => 'msg',
            'read_at'                   => $readAt,
        ]);
    }

    private function makeRequest(): RepairRequest
    {
        $uuid = (string) Str::uuid();

        $model = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => 1,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $model->id,
            'stage'             => 1,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->customer->id,
        ]);

        return $model;
    }

    private function makeVehicleChangeRequest(): VehicleChangeRequest
    {
        return VehicleChangeRequest::query()->create([
            'uuid'            => (string) Str::uuid(),
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->customerOrg->id,
            'vehicle_id'      => null,
            'requested_by'    => $this->customer->id,
            'action'          => 'create',
            'status'          => 'pending',
            'payload'         => ['vehicle' => 'Lada', 'vin' => 'XTA00000000000001'],
        ]);
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

    private function postRead(string $uuid)
    {
        return $this->call(
            'POST',
            '/api/notifications/' . $uuid . '/read',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ],
            '{}',
        );
    }
}
