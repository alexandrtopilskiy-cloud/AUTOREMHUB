<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\TimeNorm;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для RepairRequestService::customerSlotDecision().
 *
 * Заказчик принимает или отклоняет время, предложенное СТО.
 * Метод вызывается на этапе SlotReview (3).
 *
 * Ветки:
 *   - accepted    → берёт stationSlot, переходит в WaitingVehicle (4)
 *   - rescheduled → берёт новый slot (≠ stationSlot), переходит в ManagerReview (1)
 *
 * Плюс граничные случаи:
 *   - accepted без stationSlot
 *   - rescheduled без slot
 *   - rescheduled со slot == stationSlot
 *   - невалидный decision
 *   - не customer
 */
final class CustomerSlotDecisionTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGE_STATION_RESPONSE = 2;
    private const STAGE_SLOT_REVIEW      = 3;
    private const STAGE_MANAGER_REVIEW   = 1;
    private const STAGE_WAITING_VEHICLE  = 4;

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
    private TimeNorm $norm;

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
            'uuid'        => (string) Str::uuid(),
            'vehicle_id'  => $this->vehicle->id,
            'kind'        => 'contact',
            'last_name'   => 'Иванов',
            'first_name'  => 'Иван',
            'phone'       => '+79990000000',
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

        $this->norm = TimeNorm::query()->create([
            'tenant_id'        => $this->tenant->id,
            'work_type_id'     => $this->work->id,
            'norm_id'          => 'OT-00001-N001',
            'hours'            => '1.000',
            'source'           => 'Тест',
            'active'           => true,
            'catalog_version'  => 'test',
        ]);
    }

    /* ─────────────────── успешные ветки ─────────────────── */

    public function test_accepted_moves_to_waiting_vehicle(): void
    {
        $request = $this->requestAtSlotReview();

        $this->actingAs($this->customer);
        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'accepted',
                'comment'  => 'Согласен со временем',
            ],
        );

        $response->assertOk();
        $updated = $response->json('request');

        $this->assertSame(self::STAGE_WAITING_VEHICLE, $updated['stage']);

        $this->assertDatabaseHas('request_approvals', [
            'repair_request_id' => $request['systemId'],
            'decision'          => 'accepted',
        ]);

        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $request['systemId'],
            'action'            => 'customer_slot_accepted',
        ]);
    }

    public function test_rescheduled_moves_to_manager_review_with_new_slot(): void
    {
        $request = $this->requestAtSlotReview();
        $newSlot = '2026-10-22T16:00';

        $this->actingAs($this->customer);
        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'rescheduled',
                'slot'     => $newSlot,
                'comment'  => 'Нужно другое время',
            ],
        );

        $response->assertOk();
        $updated = $response->json('request');

        $this->assertSame(self::STAGE_MANAGER_REVIEW, $updated['stage']);

        $this->assertDatabaseHas('request_approvals', [
            'repair_request_id' => $request['systemId'],
            'decision'          => 'rescheduled',
        ]);

        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $request['systemId'],
            'action'            => 'customer_slot_rescheduled',
        ]);
    }

    /* ─────────────────── граничные случаи ─────────────────── */

    public function test_accepted_without_station_slot_is_rejected(): void
    {
        // Заявка на SlotReview, но stationSlot пустой — например,
        // СТО не заполнила его. Тест фиксирует 422.
        $request = $this->requestAtSlotReviewWithEmptyStationSlot();

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'accepted',
                'comment'  => 'ok',
            ],
        )->assertUnprocessable();
    }

    public function test_rescheduled_without_slot_is_rejected(): void
    {
        $request = $this->requestAtSlotReview();

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'rescheduled',
                'comment'  => 'test',
            ],
        )->assertUnprocessable();
    }

    public function test_rescheduled_with_same_slot_is_rejected(): void
    {
        $request = $this->requestAtSlotReview();
        $stationSlot = $request['stationSlot'] ?? '2026-10-21T15:00';

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'rescheduled',
                'slot'     => $stationSlot,
                'comment'  => 'test',
            ],
        )->assertUnprocessable();
    }

    public function test_unknown_decision_is_rejected(): void
    {
        $request = $this->requestAtSlotReview();

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'unknown',
                'comment'  => 'test',
            ],
        )->assertUnprocessable();
    }

    public function test_manager_cannot_use_customer_slot_decision(): void
    {
        $request = $this->requestAtSlotReview();

        $this->actingAs($this->manager);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'accepted',
                'comment'  => 'test',
            ],
        )->assertForbidden();
    }

    public function test_station_cannot_use_customer_slot_decision(): void
    {
        $request = $this->requestAtSlotReview();

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/customer-slot-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'accepted',
                'comment'  => 'test',
            ],
        )->assertForbidden();
    }

    /* ─────────────────── хелперы ─────────────────── */

    /**
     * Заявка на этапе SlotReview (3) после station-decision('rescheduled').
     *
     * @return array<string, mixed>
     */
    private function requestAtSlotReview(): array
    {
        $request = $this->requestAtStationResponse();
        $newSlot = '2026-10-21T15:00';

        $this->actingAs($this->station);
        $updated = $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision'    => $request['revision'],
                'decision'    => 'rescheduled',
                'comment'     => 'Нужно другое время',
                'stationSlot' => $newSlot,
            ],
        );

        $updated->assertOk()->json('request');

        $this->assertSame(self::STAGE_SLOT_REVIEW, $updated['request']['stage']);

        return $updated->json('request');
    }

    /**
     * То же, но принудительно очищаем stationSlot в data.
     * Нужно для кейса accepted без stationSlot.
     *
     * @return array<string, mixed>
     */
    private function requestAtSlotReviewWithEmptyStationSlot(): array
    {
        $request = $this->requestAtSlotReview();

        // Ломаем данные напрямую через модель — эмулируем кривой state.
        $model = RepairRequest::query()->where('uuid', $request['id'])->firstOrFail();
        $data = $model->data;
        $data['stationSlot'] = '';
        $model->data = $data;
        $model->save();

        return $request;
    }

    /**
     * Заявка на этапе StationResponse (2).
     *
     * @return array<string, mixed>
     */
    private function requestAtStationResponse(): array
    {
        $this->actingAs($this->customer);
        $draft = $this->postJsonObject('/api/requests')->assertCreated()->json('request');

        $draft = $this->patchJson('/api/requests/' . $draft['id'], [
            'revision' => $draft['revision'],
            'vehicleId' => $this->vehicle->uuid,
            'mileage'   => '15000',
            'contactId' => $this->contact->uuid,
            'reasonId'  => $this->reason->uuid,
            'slot'      => '2026-10-20T11:00',
        ])->assertOk()->json('request');

        $draft = $this->postJson('/api/requests/' . $draft['id'] . '/next', [
            'revision'      => $draft['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');

        $this->actingAs($this->manager);
        $draft = $this->patchJson('/api/requests/' . $draft['id'], [
            'revision' => $draft['revision'],
            'stationId' => $this->stationRecord->uuid,
            'works'     => [[
                'name'        => 'Диагностика',
                'type'        => 'Работа',
                'qty'         => 1,
                'price'       => 100,
                'workTypeId'  => $this->work->uuid,
                'timeNormId'  => $this->norm->uuid,
                'normHours'   => 1,
                'actualHours' => null,
            ]]
        ])->assertOk()->json('request');

        return $this->postJson('/api/requests/' . $draft['id'] . '/next', [
            'revision'      => $draft['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');
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
}
