<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для RepairRequestService::stationDecision().
 *
 * Покрывает 3 ветки решения СТО:
 *   - confirmed    → stage 3 (CustomerApproval)
 *   - rescheduled  → stage 3 (CustomerApproval), notifySlotChanged
 *   - declined     → stage 1 (Review)
 *
 * Плюс граничные случаи:
 *   - пустой comment разрешён
 *   - confirmed без planned_completion_at
 *   - rescheduled без stationSlot
 *   - rescheduled со stationSlot == slot
 *   - невалидный decision
 */
final class StationDecisionTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGE_STATION_RESPONSE = 2;
    private const STAGE_SLOT_REVIEW      = 3;
    private const STAGE_REVIEW           = 1;

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
    }

    /* ─────────────────── успешные ветки ─────────────────── */

    public function test_confirmed_moves_to_customer_approval_and_notifies(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision'    => $request['revision'],
                'decision'    => 'confirmed',
                'comment'     => 'Время подтверждено',
                'stationSlot' => $request['slot'],
            ],
        );

        $response->assertOk();
        $updated = $response->json('request');

        $this->assertSame(self::STAGE_SLOT_REVIEW, $updated['stage']);
        $this->assertSame($request['slot'], $updated['stationSlot'] ?? null);

        $this->assertSame(1, RequestApproval::query()
        ->where('repair_request_id', $request['systemId'])
            ->where('decision', 'confirmed')
            ->count());

        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $request['systemId'],
            'action'            => 'station_confirmed',
        ]);

        $this->assertSame(1, RequestNotification::query()
            ->where('repair_request_id', $request['systemId'])
            ->where('kind', 'customer_final_approval')
            ->count());
    }

    public function test_rescheduled_moves_to_slot_review_and_notifies(): void
    {
        $request = $this->requestAtStationResponse();
        $newSlot = '2026-10-21T15:00';

        $this->actingAs($this->station);
        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision'    => $request['revision'],
                'decision'    => 'rescheduled',
                'comment'     => 'Нужно другое время',
                'stationSlot' => $newSlot,
            ],
        );

        $response->assertOk();
        $updated = $response->json('request');

        $this->assertSame(self::STAGE_SLOT_REVIEW, $updated['stage']);

        $this->assertDatabaseHas('request_approvals', [
            'repair_request_id' => $request['systemId'],
            'decision'          => 'rescheduled',
        ]);

        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $request['systemId'],
            'action'            => 'station_rescheduled',
        ]);

        // Уведомление для customer.
        $this->assertSame(1, RequestNotification::query()
        ->where('repair_request_id', $request['systemId'])
            ->where('kind', 'station_slot_changed')
            ->count());
    }

    public function test_station_work_change_returns_request_to_manager(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $request = $this->patchJson('/api/requests/' . $request['id'], [
            'revision' => $request['revision'],
            'patch'    => [
                'works' => [[
                    'name'               => 'Диагностика',
                    'type'               => 'Работа',
                    'qty'                => 1,
                    'price'              => 100,
                    'workTypeId'         => $this->work->uuid,
                    'normHours'          => 1.5,
                    'actualHours'        => null,
                    'normIsManual'       => true,
                    'normOverrideReason' => 'Ручная норма для теста',
                ]],
            ],
        ])->assertOk()->json('request');

        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'confirmed',
                'comment'  => '',
            ],
        )->assertOk();

        $this->assertSame(self::STAGE_REVIEW, $response->json('request.stage'));
        $this->assertSame(1.5, $response->json('request.works.0.normHours'));
        $this->assertSame('', $response->json('request.works.0.timeNormId'));
        $this->assertDatabaseHas('request_notifications', [
            'repair_request_id' => $request['systemId'],
            'kind'              => 'manager_review_required',
        ]);
        $notification = RequestNotification::query()
            ->where('repair_request_id', $request['systemId'])
            ->where('kind', 'manager_review_required')
            ->firstOrFail();
        $this->assertStringContainsString('норма времени', $notification->message);
    }

    public function test_manager_approves_station_changes_and_notifies_both_parties(): void
    {
        $request = $this->stationChangesWorkAndReturnsToManager();

        $this->actingAs($this->manager);
        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/next',
            [
                'revision'      => $request['revision'],
                'comment'       => '',
                'attachmentIds' => [],
            ],
        )->assertOk();

        $response->assertJsonPath('request.stage', self::STAGE_SLOT_REVIEW);
        $response->assertJsonPath('request.reviewContext', 'initial');

        $this->assertDatabaseHas('request_notifications', [
            'repair_request_id' => $request['systemId'],
            'kind'              => 'station_estimate_approved',
        ]);
        $this->assertDatabaseHas('request_notifications', [
            'repair_request_id' => $request['systemId'],
            'kind'              => 'customer_final_approval',
        ]);
    }

    public function test_return_to_station_requires_comment_and_keeps_stage_on_error(): void
    {
        $request = $this->stationChangesWorkAndReturnsToManager();
        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/return', ['revision' => $request['revision'], 'comment' => '', 'attachmentIds' => []])->assertUnprocessable();
        $this->getJson('/api/requests/' . $request['id'])->assertOk()->assertJsonPath('request.stage', self::STAGE_REVIEW);
        $this->postJson('/api/requests/' . $request['id'] . '/return', ['revision' => $request['revision'], 'comment' => 'Уточните состав работ', 'attachmentIds' => []])->assertOk()->assertJsonPath('request.stage', self::STAGE_STATION_RESPONSE);
        $notice = RequestNotification::query()->where('repair_request_id', $request['systemId'])->where('kind', 'station_estimate_changed')->firstOrFail();
        $this->assertStringContainsString('Уточните состав работ', $notice->message);
    }

    public function test_manager_correction_returns_to_station_until_station_accepts(): void
    {
        $request = $this->stationChangesWorkAndReturnsToManager();

        $this->actingAs($this->manager);
        $request = $this->patchJson('/api/requests/' . $request['id'], [
            'revision' => $request['revision'],
            'patch'    => [
                'works' => [[
                    'name'               => 'Диагностика',
                    'type'               => 'Работа',
                    'qty'                => 1,
                    'price'              => 100,
                    'workTypeId'         => $this->work->uuid,
                    'normHours'          => 2,
                    'actualHours'        => null,
                    'normIsManual'       => true,
                    'normOverrideReason' => 'Ручная норма для теста',
                ]],
            ],
        ])->assertOk()->json('request');

        $request = $this->postJson(
            '/api/requests/' . $request['id'] . '/return',
            [
                'revision'      => $request['revision'],
                'comment'       => 'Проверьте скорректированную норму времени',
                'attachmentIds' => [],
            ],
        )->assertOk()->json('request');

        $this->assertSame(self::STAGE_STATION_RESPONSE, $request['stage']);
        $this->assertSame('manager_changes', $request['reviewContext']);
        $this->assertSame($request['estimateVersion'], $request['reviewBaselineVersion']);

        $notification = RequestNotification::query()
            ->where('repair_request_id', $request['systemId'])
            ->where('kind', 'station_estimate_changed')
            ->firstOrFail();
        $this->assertStringContainsString('норма времени', $notification->message);
        $this->assertStringContainsString('«1.500» → «2.000»', $notification->message);

        $this->actingAs($this->station);
        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'confirmed',
                'comment'  => '',
            ],
        )->assertOk();

        $response->assertJsonPath('request.stage', self::STAGE_SLOT_REVIEW);
        $response->assertJsonPath('request.reviewContext', 'initial');
        $this->assertDatabaseHas('request_notifications', [
            'repair_request_id' => $request['systemId'],
            'kind'              => 'customer_final_approval',
        ]);
    }

    public function test_declined_moves_to_review(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $response = $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'declined',
                'comment'  => 'Нет свободного поста',
            ],
        );

        $response->assertOk();
        $updated = $response->json('request');

        $this->assertSame(self::STAGE_REVIEW, $updated['stage']);

        $this->assertDatabaseHas('request_approvals', [
            'repair_request_id' => $request['systemId'],
            'decision'          => 'declined',
        ]);

        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $request['systemId'],
            'action'            => 'station_declined',
        ]);

        $this->assertGreaterThanOrEqual(1, RequestNotification::query()
            ->where('repair_request_id', $request['systemId'])
            ->where('kind', 'manager_review_required')
            ->count());
    }

    /* ─────────────────── граничные случаи ─────────────────── */

    public function test_empty_comment_is_allowed(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'confirmed',
                'comment'  => '',
            ],
        )->assertOk();
    }

    public function test_unknown_decision_is_rejected(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'unknown',
                'comment'  => 'test',
            ],
        )->assertUnprocessable();
    }

    public function test_rescheduled_without_station_slot_is_rejected(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'rescheduled',
                'comment'  => 'Нужно другое время',
            ],
        )->assertUnprocessable();
    }

    public function test_rescheduled_with_same_slot_is_rejected(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision'    => $request['revision'],
                'decision'    => 'rescheduled',
                'comment'     => 'Нужно другое время',
                'stationSlot' => $request['slot'],
            ],
        )->assertUnprocessable();
    }

    public function test_customer_cannot_use_station_decision(): void
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'confirmed',
                'comment'  => 'test',
            ],
        )->assertForbidden();
    }

    public function test_decline_requires_comment_and_repeat_routing_requires_acknowledgement(): void
    {
        $request = $this->requestAtStationResponse();
        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/station-decision', ['revision' => $request['revision'], 'decision' => 'declined', 'comment' => ''])->assertUnprocessable();
        $declined = $this->postJson('/api/requests/' . $request['id'] . '/station-decision', ['revision' => $request['revision'], 'decision' => 'declined', 'comment' => 'Нет поста'])->assertOk()->json('request');
        $this->assertSame('Маршрутизация УК (отказ СТО)', $declined['statusLabel']);
        $this->assertContains($this->stationRecord->uuid, $declined['declinedStationIds']);
        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/next', ['revision' => $declined['revision'], 'comment' => '', 'attachmentIds' => []])->assertConflict();
        $this->postJson('/api/requests/' . $request['id'] . '/next', ['revision' => $declined['revision'], 'comment' => '', 'attachmentIds' => [], 'confirmDeclinedStation' => true])->assertOk()->assertJsonPath('request.stage', 2);
    }

    public function test_station_changed_estimate_has_explicit_review_status(): void
    {
        $request = $this->stationChangesWorkAndReturnsToManager();
        $this->assertSame('Согласование работ / з/ч', $request['statusLabel']);
    }

    public function test_station_part_price_is_independent_and_changes_return_to_manager(): void
    {
        $request = $this->requestAtStationResponse();
        $manufacturer = app(\App\Services\PartManufacturerService::class)->write($this->manager, null, ['name' => 'Price test']);
        $supplier = \App\Models\Supplier::query()->create(['tenant_id' => $this->manager->tenantId(), 'name' => 'Price supplier', 'active' => true]);
        $part = app(\App\Services\CatalogService::class)->create($this->station, 'parts', ['name' => 'Filter', 'article' => 'PF1', 'unit' => 'шт.', 'manufacturerId' => $manufacturer['id'], 'supplierId' => $supplier->uuid, 'purchasePrice' => 100, 'salePrice' => 150]);
        $this->actingAs($this->station);
        $changed = $this->postJson('/api/requests/' . $request['id'] . '/parts', ['revision' => $request['revision'], 'catalogPartId' => $part['id'], 'name' => 'Filter', 'article' => 'PF1', 'unit' => 'шт.', 'supplierId' => $supplier->uuid, 'qty' => 1, 'price' => 275])->assertCreated()->json('request');
        $this->assertSame(275.0, (float) $changed['parts'][0]['price']);
        $this->assertDatabaseHas('catalog_parts', ['uuid' => $part['id'], 'sale_price' => 150]);
        $review = $this->postJson('/api/requests/' . $request['id'] . '/station-decision', ['revision' => $changed['revision'], 'decision' => 'confirmed', 'comment' => ''])->assertOk()->json('request');
        $this->assertSame('Согласование работ / з/ч', $review['statusLabel']);
    }

    /* ─────────────────── хелперы ─────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function stationChangesWorkAndReturnsToManager(): array
    {
        $request = $this->requestAtStationResponse();

        $this->actingAs($this->station);
        $request = $this->patchJson('/api/requests/' . $request['id'], [
            'revision' => $request['revision'],
            'patch'    => [
                'works' => [[
                    'name'               => 'Диагностика',
                    'type'               => 'Работа',
                    'qty'                => 1,
                    'price'              => 100,
                    'workTypeId'         => $this->work->uuid,
                    'normHours'          => 1.5,
                    'actualHours'        => null,
                    'normIsManual'       => true,
                    'normOverrideReason' => 'Ручная норма для теста',
                ]],
            ],
        ])->assertOk()->json('request');

        return $this->postJson(
            '/api/requests/' . $request['id'] . '/station-decision',
            [
                'revision' => $request['revision'],
                'decision' => 'confirmed',
                'comment'  => '',
            ],
        )->assertOk()->json('request');
    }

    /**
     * Создаёт заявку на этапе StationResponse (2) с активным периодом.
     * Возвращает ответ API, а не модель — чтобы дальше работать как в feature-тестах.
     *
     * @return array<string, mixed>
     */
    private function requestAtStationResponse(): array
    {
        // Шаг 1: customer создаёт черновик.
        $this->actingAs($this->customer);
        $draft = $this->postJsonObject('/api/requests')->assertCreated()->json('request');

        // Шаг 2: customer заполняет и переводит на Review (1).
        $draft = $this->patchJson('/api/requests/' . $draft['id'], [
            'revision' => $draft['revision'],
            'patch'    => [
                'vehicleId' => $this->vehicle->uuid,
                'mileage'   => '15000',
                'contactId' => $this->contact->uuid,
                'reasonId'  => $this->reason->uuid,
                'slot'      => '2026-10-20T11:00',
            ],
        ])->assertOk()->json('request');

        $draft = $this->postJson('/api/requests/' . $draft['id'] . '/next', [
            'revision'      => $draft['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');

        // Шаг 3: manager добавляет СТО + работу и переводит на StationResponse (2).
        $this->actingAs($this->manager);
        $draft = $this->patchJson('/api/requests/' . $draft['id'], [
            'revision' => $draft['revision'],
            'patch'    => [
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
            ],
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
