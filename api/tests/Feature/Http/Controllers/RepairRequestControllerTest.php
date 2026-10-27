<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAcceptanceAct;
use App\Models\VehicleContact;
use App\Models\WorkType;
use App\Services\ReferenceSeeder;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для RepairRequestController.
 *
 * Покрывает:
 *   - GET    /api/requests                                       (index)
 *   - POST   /api/requests                                       (store)
 *   - GET    /api/requests/{id}                                  (show)
 *   - PATCH  /api/requests/{id}                                  (update)
 *   - DELETE /api/requests/{id}                                  (destroy)
 *   - POST   /api/requests/drafts/delete                         (destroyDrafts)
 *   - POST   /api/requests/{id}/next                             (next)
 *   - POST   /api/requests/{id}/return                           (returnBack)
 *   - POST   /api/requests/{id}/station-decision                 (stationDecision)
 *   - POST   /api/requests/{id}/customer-slot-decision           (customerSlotDecision)
 *   - POST   /api/requests/{id}/acceptance-act                   (acceptVehicle)
 *   - GET    /api/requests/{id}/history                          (history)
 *   - GET    /api/requests/{id}/work-order                       (workOrder)
 */
final class RepairRequestControllerTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private User $station;
    private Station $stationRecord;
    private WorkType $work;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');

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

        $this->customer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager  = $this->makeUser($this->managerOrg, 'manager');
        $this->station  = $this->makeUser($this->stationOrg, 'station');

        $this->stationRecord = Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->stationOrg->id,
            'name'            => 'Тестовая СТО',
            'address'         => 'Москва',
            'active'          => true,
        ]);

        $this->work = WorkType::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Диагностика',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);

        app(ReferenceSeeder::class)->seed($this->tenant->id);
    }

    /* ─────────────────── index ─────────────────── */

    public function test_index_returns_requests_for_customer(): void
    {
        $own = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/requests')->assertOk();

        $ids = collect($response->json('requests'))->pluck('id')->all();
        $this->assertContains($own->uuid, $ids);
    }

    public function test_index_returns_requests_for_manager(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_MANAGER_ROUTING);

        $this->actingAs($this->manager);
        $response = $this->getJson('/api/requests')->assertOk();

        $ids = collect($response->json('requests'))->pluck('id')->all();
        $this->assertContains($request->uuid, $ids);
    }

    public function test_index_returns_requests_for_station(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_STATION_APPROVAL, $this->stationRecord);

        $this->actingAs($this->station);
        $response = $this->getJson('/api/requests')->assertOk();

        $ids = collect($response->json('requests'))->pluck('id')->all();
        $this->assertContains($request->uuid, $ids);
    }

    /* ─────────────────── show ─────────────────── */

    public function test_show_returns_request_for_owner(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->getJson('/api/requests/' . $request->uuid)
            ->assertOk()
            ->assertJsonPath('request.id', $request->uuid)
            ->assertJsonPath('request.stage', Workflow::STAGE_DRAFT);
    }

    public function test_show_returns_404_for_unknown_uuid(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/requests/' . Str::uuid())
            ->assertNotFound();
    }

    /* ─────────────────── store ─────────────────── */

    public function test_store_creates_draft_for_customer(): void
    {
        $this->actingAs($this->customer);
        $this->postJsonObject('/api/requests')
            ->assertCreated()
            ->assertJsonPath('request.stage', Workflow::STAGE_DRAFT)
            ->assertJsonPath('request.revision', 0);

        $this->assertDatabaseHas('repair_requests', [
            'tenant_id' => $this->tenant->id,
            'stage'     => Workflow::STAGE_DRAFT,
        ]);
    }

    public function test_store_forbidden_for_manager(): void
    {
        $this->actingAs($this->manager);
        $this->postJsonObject('/api/requests')
            ->assertForbidden();
    }

    public function test_store_forbidden_for_station(): void
    {
        $this->actingAs($this->station);
        $this->postJsonObject('/api/requests')
            ->assertForbidden();
    }

    /* ─────────────────── update ─────────────────── */

    public function test_update_changes_mileage_for_customer(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->patchJson('/api/requests/' . $request->uuid, [
            'revision'  => $request->revision,
            'mileage'   => '12345',
        ])
            ->assertOk();

        $this->assertSame('12345', $request->refresh()->data['mileage']);
    }

    public function test_update_returns_409_for_revision_conflict(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->patchJson('/api/requests/' . $request->uuid, [
            'revision'  => $request->revision + 99,
            'mileage'   => '12345',
        ])->assertStatus(409);
    }

    public function test_update_rejects_disallowed_fields(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->patchJson('/api/requests/' . $request->uuid, [
            'revision'      => $request->revision,
            'unknownField'  => 'value',
        ])->assertUnprocessable();
    }

    /* ─────────────────── destroy ─────────────────── */

    public function test_destroy_deletes_draft_for_owner(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->deleteJson('/api/requests/' . $request->uuid)
            ->assertOk()
            ->assertJsonPath('deletedIds', [$request->uuid]);

        $this->assertDatabaseMissing('repair_requests', ['id' => $request->id]);
    }

    public function test_destroy_returns_403_for_non_draft(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_MANAGER_ROUTING);

        $this->actingAs($this->customer);
        $this->deleteJson('/api/requests/' . $request->uuid)
            ->assertForbidden();
    }

    public function test_destroy_returns_404_for_unknown_uuid(): void
    {
        $this->actingAs($this->customer);
        $this->deleteJson('/api/requests/' . Str::uuid())
            ->assertNotFound();
    }

    /* ─────────────────── destroyDrafts ─────────────────── */

    public function test_destroy_drafts_deletes_multiple_drafts(): void
    {
        $first = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);
        $second = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/drafts/delete', [
            'ids' => [$first->uuid, $second->uuid],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'deletedIds');

        $this->assertDatabaseMissing('repair_requests', ['id' => $first->id]);
        $this->assertDatabaseMissing('repair_requests', ['id' => $second->id]);
    }

    public function test_destroy_drafts_rejects_empty_ids(): void
    {
        $this->actingAs($this->customer);
        $this->postJson('/api/requests/drafts/delete', ['ids' => []])
            ->assertUnprocessable();
    }

    /* ─────────────────── next / return ─────────────────── */

    public function test_next_advances_draft_to_manager_routing(): void
    {
        $request = $this->makeDraftReadyForSubmit();

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request->uuid . '/next', [
            'revision'      => $request->revision,
            'comment'       => '',
            'attachmentIds' => [],
        ])
            ->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_MANAGER_ROUTING);
    }

    public function test_next_rejects_manager_routing_without_works(): void
    {
        $request = $this->makeManagerRoutingRequest();

        $this->actingAs($this->manager);
        $response = $this->postJson('/api/requests/' . $request->uuid . '/next', [
            'revision'      => $request->revision,
            'comment'       => '',
            'attachmentIds' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.message', 'Добавьте хотя бы одну работу');
    }

    public function test_return_on_station_approval_requires_station_decision(): void
    {
        $request = $this->makeRequest(
            $this->customer,
            Workflow::STAGE_STATION_APPROVAL,
            $this->stationRecord,
        );

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request->uuid . '/return', [
            'revision'      => $request->revision,
            'comment'       => 'Не можем',
            'attachmentIds' => [],
        ])->assertForbidden();
    }

    /* ─────────────────── stationDecision ─────────────────── */

    public function test_station_decision_confirms_slot(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_STATION_APPROVAL, $this->stationRecord);
        $request->update([
            'data' => array_replace($request->data, [
                'slot' => '2026-10-20T11:00',
            ]),
        ]);
        $this->makeWork($request);

        $this->actingAs($this->station);
        $res = $this->postJson('/api/requests/' . $request->uuid . '/station-decision', [
            'revision' => $request->revision,
            'decision' => 'confirmed',
            'comment'  => 'Подтверждаем',
        ]);

        $res->assertOk();
        $res->assertJsonPath('request.stage', Workflow::STAGE_CUSTOMER_APPROVAL);
    }

    public function test_station_decision_reschedules_slot(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_STATION_APPROVAL, $this->stationRecord);
        $request->update([
            'data'                => array_replace($request->data, [
                'slot' => '2026-10-20T11:00',
            ]),
        ]);

        $this->makeWork($request);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request->uuid . '/station-decision', [
            'revision' => $request->revision,
            'decision' => 'confirmed',
            'comment'  => 'Подтверждаем',
        ])
            ->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_CUSTOMER_APPROVAL);
    }

    public function test_station_decision_declines(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_STATION_APPROVAL);
        $request->update([
            'assigned_station_id' => $this->stationRecord->id,
            'data'                => array_replace($request->data, [
                'slot' => '2026-10-20T11:00',
            ]),
        ]);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request->uuid . '/station-decision', [
            'revision' => $request->revision,
            'decision' => 'declined',
            'comment'  => 'Отказываемся',
        ])
            ->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_MANAGER_ROUTING);
    }

    /* ─────────────────── customerSlotDecision ─────────────────── */

    public function test_customer_accepts_station_slot(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_CUSTOMER_APPROVAL);
        $request->update([
            'assigned_station_id' => $this->stationRecord->id,
            'data' => array_replace($request->data, [
                'slot'        => '2026-10-20T11:00',
                'stationSlot' => '2026-10-21T14:00',
            ]),
        ]);

        $this->actingAs($this->customer);
        $response = $this->postJson('/api/requests/' . $request->uuid . '/customer-slot-decision', [
            'revision' => $request->revision,
            'decision' => 'accepted',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_WAITING_VEHICLE);
    }

    public function test_customer_reschedules_slot(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_CUSTOMER_APPROVAL);
        $request->update([
            'data' => array_replace($request->data, [
                'slot'        => '2026-10-20T11:00',
                'stationSlot' => '2026-10-21T14:00',
            ]),
        ]);

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request->uuid . '/customer-slot-decision', [
            'revision' => $request->revision,
            'decision' => 'rescheduled',
            'slot'     => '2026-10-22T10:00',
            'comment'  => 'Не могу в это время',
        ])
            ->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_MANAGER_ROUTING);
    }

    /* ─────────────────── acceptVehicle ─────────────────── */

    public function test_accept_vehicle_electronic_creates_act(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_WAITING_VEHICLE);
        $request->update(['assigned_station_id' => $this->stationRecord->id]);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request->uuid . '/acceptance-act', [
            'revision'     => $request->revision,
            'mode'         => 'electronic',
            'mileage'      => 15000,
            'fuelLevel'    => 'half',
            'condition'    => 'Без видимых повреждений',
            'defectTypes'  => [],
            'diagramMarks' => [],
        ])
            ->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_REPAIR_IN_PROGRESS);

        $this->assertDatabaseHas('vehicle_acceptance_acts', [
            'repair_request_id' => $request->id,
            'mode'              => 'electronic',
            'mileage'           => 15000,
        ]);
    }

    public function test_accept_vehicle_rejects_second_act(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_WAITING_VEHICLE);
        $request->update(['assigned_station_id' => $this->stationRecord->id]);

        VehicleAcceptanceAct::query()->create([
            'repair_request_id' => $request->id,
            'station_id'        => $this->stationRecord->id,
            'created_by'        => $this->station->id,
            'number'            => 'AP-' . $request->number,
            'mode'              => 'electronic',
            'vehicle_snapshot'  => [],
            'mileage'           => 100,
            'condition'         => '',
            'defect_types'      => [],
            'diagram_marks'     => [],
            'locked_at'         => now(),
        ]);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request->uuid . '/acceptance-act', [
            'revision'  => $request->revision,
            'mode'      => 'electronic',
            'mileage'   => 15000,
            'fuelLevel' => 'half',
            'condition' => 'OK',
        ])->assertStatus(409);
    }

    /* ─────────────────── history / workOrder ─────────────────── */

    public function test_history_returns_events_and_periods(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->getJson('/api/requests/' . $request->uuid . '/history')
            ->assertOk()
            ->assertJsonStructure(['history', 'statusPeriods', 'approvals']);
    }

    public function test_work_order_returns_null_when_not_exists(): void
    {
        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);

        $this->actingAs($this->customer);
        $this->getJson('/api/requests/' . $request->uuid . '/work-order')
            ->assertOk()
            ->assertJsonPath('workOrder', null);
    }

    /* ─────────────────── helpers ─────────────────── */

    private function makeRequest(
        User $creator,
        int $stage,
        ?Station $station = null,
    ): RepairRequest {
        $uuid = (string) Str::uuid();
        $estimateVersion = 1;
        // На stage >= 2 смета уже была зафиксирована для текущего согласующего.
        $reviewBaselineVersion = $stage >= Workflow::STAGE_STATION_APPROVAL
            ? $estimateVersion
            : 0;

        $request = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'assigned_station_id'      => $station?->id,
            'customer_organization_id' => $creator->organization_id,
            'created_by'               => $creator->id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => $stage,
            'revision'                 => 1,
            'review_context'           => 'initial',
            'estimate_version'         => $estimateVersion,
            'review_baseline_version'  => $reviewBaselineVersion,
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'stage'             => $stage,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $creator->id,
        ]);

        return $request;
    }

    private function makeDraftReadyForSubmit(): RepairRequest
    {
        $vehicle = Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
        ]);

        $contact = VehicleContact::query()->create([
            'vehicle_id' => $vehicle->id,
            'kind'       => 'contact',
            'last_name'  => 'Иванов',
            'first_name' => 'Иван',
            'phone'      => '+79990000001',
            'active'     => true,
        ]);

        $reason = ServiceReason::query()
        ->where('tenant_id', $this->tenant->id)
            ->where('code', 'diagnostics')
            ->firstOrFail();

        $request = $this->makeRequest($this->customer, Workflow::STAGE_DRAFT);
        $request->update([
            'vehicle_id'    => $vehicle->id,
            'contact_id'    => $contact->id,
            'reason_id'     => $reason->id,
            'data'          => array_replace($request->data, [
                'mileage' => '15000',
                'slot'    => '2026-10-20T11:00',
            ]),
        ]);

        return $request->refresh();
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

    private function makeManagerRoutingRequest(): RepairRequest
    {
        $this->actingAs($this->customer);
        $created = $this->postJsonObject('/api/requests')->assertCreated()->json('request');

        $vehicle = Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA' . str_pad((string) random_int(1, 99999999999999), 14, '0'),
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
            'labor_hour_rate'  => 1000,
        ]);
        $contact = VehicleContact::query()->create([
            'vehicle_id' => $vehicle->id,
            'kind'       => 'contact',
            'last_name'  => 'Иванов',
            'first_name' => 'Иван',
            'phone'      => '+79990000001',
            'active'     => true,
        ]);
        $reason = ServiceReason::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('code', 'diagnostics')
            ->firstOrFail();

        $response = $this->patchJson('/api/requests/' . $created['id'], [
            'revision'  => $created['revision'],
            'vehicleId' => $vehicle->uuid,
            'mileage'   => '15000',
            'contactId' => $contact->uuid,
            'reasonId'  => $reason->uuid,
            'slot'      => '2026-10-20T11:00',
        ]);

        $response->assertOk();

        $advanced = $this->postJson('/api/requests/' . $created['id'] . '/next', [
            'revision'      => $created['revision'] + 1,
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');

        $request = RepairRequest::query()->where('uuid', $advanced['id'])->firstOrFail();

        $this->actingAs($this->manager);
        $this->patchJson('/api/requests/' . $request->uuid, [
            'revision'  => $request->revision,
            'stationId' => $this->stationRecord->uuid,
        ])->assertOk();

        return $request->refresh();
    }

    private function makeWork(RepairRequest $request): void
    {
        RequestWork::query()->create([
            'repair_request_id' => $request->id,
            'work_type_id'      => $this->work->id,
            'name'              => 'Диагностика',
            'quantity'          => 1,
            'price'             => 1000,
            'hourly_rate'       => 1000,
            'norm_hours'        => 1,
            'line_amount'       => 1000,
            'actual_hours'      => null,
            'is_maintenance'    => false,
        ]);
    }
}