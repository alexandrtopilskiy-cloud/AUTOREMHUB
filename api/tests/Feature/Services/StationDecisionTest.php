<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;
use App\Models\Organization;
use App\Models\RequestApproval;
use App\Models\RequestNotification;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Models\WorkType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для RepairRequestService::stationDecision().
 * Покрывает: confirmed, rescheduled, declined, а также граничные случаи.
 */
final class StationDecisionTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private User $station;
    private Vehicle $vehicle;
    private VehicleContact $contact;
    private Station $stationRecord;
    private ServiceReason $reason;
    private WorkType $work;

    protected function setUp(): void
    {
        parent::setUp();

        // Фиксируем время, чтобы тесты были детерминированными
        $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = $this->createOrg('customer', 'ООО «Заказчик»');
        $this->managerOrg  = $this->createOrg('manager', 'УК');
        $this->stationOrg  = $this->createOrg('station', 'СТО');

        $this->customer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager  = $this->makeUser($this->managerOrg, 'manager');
        $this->station  = $this->makeUser($this->stationOrg, 'station');

        $this->vehicle = Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
            'labor_hour_rate'  => 1000,
        ]);

        $this->contact = VehicleContact::query()->create([
            'uuid'       => (string) Str::uuid(),
            'vehicle_id' => $this->vehicle->id,
            'kind'       => 'contact',
            'last_name'  => 'Иванов',
            'first_name' => 'Иван',
            'phone'      => '+79990000000',
        ]);

        $this->stationRecord = Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->stationOrg->id,
            'name'            => 'Тестовая СТО',
            'address'         => 'Москва',
        ]);

        $this->reason = ServiceReason::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Диагностика',
            'code'      => 'diagnostics',
            'active'    => true,
        ]);

        $this->work = WorkType::query()->create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Диагностика',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);
    }

    /* ─────────────────── Успешные ветки ─────────────────── */

    public function test_confirmed_moves_to_customer_approval_and_notifies(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $response = $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision'    => $request['revision'],
            'decision'    => ApprovalDecision::Confirmed->value,
            'comment'     => 'Время подтверждено',
            'stationSlot' => $request['slot'],
        ]);

        $response->assertOk();
        $updated = $response->json('request');

        $this->assertSame(RepairStage::CustomerApproval->value, $updated['stage']);
        $this->assertSame($request['slot'], $updated['stationSlot'] ?? null);

        $this->assertApprovalExists($request['systemId'], ApprovalDecision::Confirmed);
        $this->assertEventExists($request['systemId'], 'station_confirmed');
        $this->assertNotificationExists($request['systemId'], 'customer_final_approval');
    }

    public function test_rescheduled_moves_to_slot_review_and_notifies(): void
    {
        $request = $this->requestAtStationResponse();
        $newSlot = '2026-10-21T15:00';

        $this->actingAs($this->station);
        $response = $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision'    => $request['revision'],
            'decision'    => ApprovalDecision::Rescheduled->value,
            'comment'     => 'Нужно другое время',
            'stationSlot' => $newSlot,
        ]);

        $response->assertOk();
        $this->assertSame(RepairStage::CustomerApproval->value, $response->json('request.stage'));

        $this->assertApprovalExists($request['systemId'], ApprovalDecision::Rescheduled);
        $this->assertEventExists($request['systemId'], 'station_rescheduled');
        $this->assertNotificationExists($request['systemId'], 'station_slot_changed');
    }

    public function test_declined_moves_to_manager_routing(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $response = $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision' => $request['revision'],
            'decision' => ApprovalDecision::Declined->value,
            'comment'  => 'Нет свободного поста',
        ]);

        $response->assertOk();
        $updated = $response->json('request');

        $this->assertSame(RepairStage::ManagerRouting->value, $updated['stage']);
        $this->assertApprovalExists($request['systemId'], ApprovalDecision::Declined);
        $this->assertEventExists($request['systemId'], 'station_declined');
        $this->assertNotificationExists($request['systemId'], 'manager_review_required', 1); // хотя бы 1
    }

    /* ─────────────────── Граничные случаи ─────────────────── */

    public function test_empty_comment_is_allowed_for_confirmed(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision' => $request['revision'],
            'decision' => ApprovalDecision::Confirmed->value,
            'comment'  => '',
        ])->assertOk();
    }

    public function test_unknown_decision_is_rejected(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision' => $request['revision'],
            'decision' => 'unknown',
            'comment'  => 'test',
        ])->assertUnprocessable();
    }

    public function test_rescheduled_without_station_slot_is_rejected(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision' => $request['revision'],
            'decision' => ApprovalDecision::Rescheduled->value,
            'comment'  => 'Нужно другое время',
        ])->assertUnprocessable();
    }

    public function test_rescheduled_with_same_slot_is_rejected(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision'    => $request['revision'],
            'decision'    => ApprovalDecision::Rescheduled->value,
            'comment'     => 'Нужно другое время',
            'stationSlot' => $request['slot'],
        ])->assertUnprocessable();
    }

    public function test_customer_cannot_use_station_decision(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->customer);
        $this->postJson("/api/requests/{$request['id']}/station-decision", [
            'revision' => $request['revision'],
            'decision' => ApprovalDecision::Confirmed->value,
            'comment'  => 'test',
        ])->assertForbidden();
    }

    /* ─────────────────── Хелперы ─────────────────── */

    private function createOrg(string $kind, string $name): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => $kind,
            'name'      => $name,
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

    /**
     * Утверждает, что в БД есть одобрение с нужным решением.
     */
    private function assertApprovalExists(int $requestSystemId, ApprovalDecision $decision): void
    {
        $this->assertSame(1, RequestApproval::query()
            ->where('repair_request_id', $requestSystemId)
            ->where('decision', $decision->value)
            ->count(), "Ожидалось одобрение с решением {$decision->value}");
    }

    /**
     * Утверждает, что в БД есть событие с нужным action.
     */
    private function assertEventExists(int $requestSystemId, string $action): void
    {
        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $requestSystemId,
            'action'            => $action,
        ]);
    }

    /**
     * Утверждает, что в БД есть уведомление с нужным kind.
     */
    private function assertNotificationExists(int $requestSystemId, string $kind, int $minCount = 1): void
    {
        $count = RequestNotification::query()
            ->where('repair_request_id', $requestSystemId)
            ->where('kind', $kind)
            ->count();

        $this->assertGreaterThanOrEqual($minCount, $count, "Ожидалось >= {$minCount} уведомлений с kind={$kind}");
    }

    /**
     * Создаёт заявку на этапе StationResponse с активным периодом.
     *
     * @return array<string, mixed>
     */
    private function requestAtStationResponse(): array
    {
        // 1. Черновик
        $this->actingAs($this->customer);
        $draft = $this->postJsonObject('/api/requests')->assertCreated()->json('request');

        // 2. Заполнение и переход на Review (ManagerRouting)
        $draft = $this->patchJson("/api/requests/{$draft['id']}", [
            'revision' => $draft['revision'],
            'vehicleId' => $this->vehicle->uuid,
            'mileage'   => '15000',
            'contactId' => $this->contact->uuid,
            'reasonId'  => $this->reason->uuid,
            'slot'      => '2026-10-20T11:00',
        ])->assertOk()->json('request');

        $draft = $this->postJson("/api/requests/{$draft['id']}/next", [
            'revision'      => $draft['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');

        // 3. Manager назначает СТО и работу, переводит на StationResponse (2)
        $this->actingAs($this->manager);
        $draft = $this->patchJson("/api/requests/{$draft['id']}", [
            'revision' => $draft['revision'],
            'stationId' => $this->stationRecord->uuid,
            'works'     => [[
                'name'               => 'Диагностика',
                'type'               => 'Работа',
                'qty'                => 1,
                'price'              => 100,
                'workTypeId'         => $this->work->uuid,
                'normHours'          => 1,
                'actualHours'        => null,
                'normIsManual'       => true,
                'normOverrideReason' => 'Ручная норма для теста',
            ]],
        ])->assertOk()->json('request');

        return $this->postJson("/api/requests/{$draft['id']}/next", [
            'revision'      => $draft['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');
    }
}
