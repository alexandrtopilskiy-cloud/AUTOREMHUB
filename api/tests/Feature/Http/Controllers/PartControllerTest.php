<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Enums\RequestPartStatus;
use App\Models\CatalogChangeEvent;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\PartMovement;
use App\Models\RepairRequest;
use App\Models\RequestPart;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для PartController.
 *
 * Разделение по роли × stage:
 *   - store/update/remove: manager на Review (1) и СТО на Approval (2)
 *   - status/movement:     station на RepairInProgress (5)
 */
final class PartControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGE_REVIEW = 1;
    private const STAGE_STATION_APPROVAL = 2;
    private const STAGE_REPAIR_IN_PROGRESS = 5;

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
    private Supplier $supplier;
    private ServiceReason $reason;

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

        $this->supplier = Supplier::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Поставщик',
            'active'    => true,
        ]);

        $this->reason = ServiceReason::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Диагностика',
            'code'      => 'diagnostics',
            'active'    => true,
        ]);
    }

    /* ─────────────────── store (manager, stage=1) ─────────────────── */

    public function test_store_creates_part_and_audit(): void
    {
        $manufacturer = \App\Models\PartManufacturer::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bosch', 'normalized_name' => 'bosch']);
        $catalog = app(\App\Services\CatalogService::class)->create($this->manager, 'parts', ['name' => 'Фильтр', 'article' => 'F-1', 'unit' => 'шт.', 'manufacturerId' => $manufacturer->uuid, 'supplierId' => $this->supplier->uuid, 'purchasePrice' => 100, 'salePrice' => 150]);
        $request = $this->requestAtReview();

        $this->actingAs($this->manager);
        $response = $this->postJson('/api/requests/' . $request['id'] . '/parts', [
            'revision'      => $request['revision'],
            'name'          => 'Фильтр',
            'article'       => 'F-1',
            'catalogPartId' => $catalog['id'],
            'unit'          => 'шт.',
            'supplierId'    => $this->supplier->uuid,
            'qty'           => 2,
            'price'         => 500,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('request_parts', [
            'repair_request_id' => $request['systemId'],
            'name'              => 'Фильтр',
            'status'            => 'proposed',
        ]);
        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'parts')
            ->where('action', 'create')
            ->count());
    }

    public function test_store_without_permission_is_forbidden(): void
    {
        $request = $this->requestAtReview();

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/parts', [
            'revision'      => $request['revision'],
            'name'          => 'Фильтр',
            'article'       => 'F-1',
            'catalogPartId' => '',
            'unit'          => 'шт.',
            'supplierId'    => '',
            'qty'           => 2,
            'price'         => 500,
        ])->assertForbidden();
    }

    public function test_store_validates_unit(): void
    {
        $request = $this->requestAtReview();

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/parts', [
            'revision'      => $request['revision'],
            'name'          => 'Фильтр',
            'article'       => 'F-1',
            'catalogPartId' => '',
            'unit'          => 'invalid-unit',
            'supplierId'    => '',
            'qty'           => 2,
            'price'         => 500,
        ])->assertUnprocessable();
    }

    public function test_station_requires_catalog_record_before_adding_part(): void
    {
        $request = $this->requestAtStationApproval();
        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts', ['revision' => $request['revision'], 'catalogPartId' => '', 'name' => 'Free text', 'article' => 'F1', 'unit' => 'шт.', 'supplierId' => $this->supplier->uuid, 'qty' => 1, 'price' => 100])->assertUnprocessable();
    }

    public function test_update_existing_part(): void
    {
        $request = $this->requestAtReview();
        $part = $this->makePart($request, 'proposed', null);

        $this->actingAs($this->manager);
        $this->patchJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid, [
            'revision'      => $request['revision'],
            'name'          => 'Новое имя',
            'article'       => 'F-2',
            'catalogPartId' => '',
            'unit'          => 'шт.',
            'supplierId'    => $this->supplier->uuid,
            'qty'           => 3,
            'price'         => 600,
        ])->assertOk();

        $this->assertSame('Новое имя', $part->refresh()->name);
    }

    public function test_update_rejects_part_after_ordered_status(): void
    {
        $request = $this->requestAtReview();
        $part = $this->makePart($request, 'ordered', null);

        $this->actingAs($this->manager);
        $this->patchJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid, [
            'revision'      => $request['revision'],
            'name'          => 'Нельзя',
            'article'       => 'F-3',
            'catalogPartId' => '',
            'unit'          => 'шт.',
            'supplierId'    => '',
            'qty'           => 1,
            'price'         => 100,
        ])->assertUnprocessable();
    }

    public function test_update_rejects_part_with_returns(): void
    {
        $request = $this->requestAtReview();
        $part = $this->makePart($request, 'proposed', null, returned: '1.000');

        $this->actingAs($this->manager);
        $this->patchJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid, [
            'revision'      => $request['revision'],
            'name'          => 'Нельзя',
            'article'       => 'F-3',
            'catalogPartId' => '',
            'unit'          => 'шт.',
            'supplierId'    => '',
            'qty'           => 1,
            'price'         => 100,
        ])->assertUnprocessable();
    }

    /* ─────────────────── remove (manager, stage=1) ─────────────────── */

    public function test_remove_deletes_proposed_part_and_bumps_version(): void
    {
        $request = $this->requestAtReview();
        $part = $this->makePart($request, 'proposed', null);
        $versionBefore = $request['estimateVersion'] ?? 1;

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/remove', [
            'revision' => $request['revision'],
        ])->assertOk();

        $this->assertDatabaseMissing('request_parts', ['id' => $part->id]);

        $model = RepairRequest::query()->where('uuid', $request['id'])->firstOrFail();
        $this->assertGreaterThan($versionBefore, $model->estimate_version);
    }

    public function test_remove_rejects_ordered_part(): void
    {
        $request = $this->requestAtReview();
        $part = $this->makePart($request, 'ordered', null);

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/remove', [
            'revision' => $request['revision'],
        ])->assertUnprocessable();
    }

    public function test_remove_rejects_part_with_movements(): void
    {
        $request = $this->requestAtReview();
        $part = $this->makePart($request, 'proposed', null);

        PartMovement::query()->create([
            'uuid'            => (string) Str::uuid(),
            'request_part_id' => $part->id,
            'actor_id'        => $this->manager->id,
            'kind'            => 'return',
            'quantity'        => 1,
            'comment'         => 'test',
            'attachment_ids'  => [],
        ]);

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/remove', [
            'revision' => $request['revision'],
        ])->assertUnprocessable();
    }

    /* ─────────────────── status (station, stage=7) ─────────────────── */

    public function test_status_approved_to_ordered(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'approved', 1);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/status', [
            'revision' => $request['revision'],
            'status'   => 'ordered',
            'comment'  => 'Заказано у поставщика',
        ])->assertOk();

        $this->assertSame(RequestPartStatus::Ordered, $part->refresh()->status);
    }

    public function test_status_ordered_to_received(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'ordered', 1);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/status', [
            'revision' => $request['revision'],
            'status'   => 'received',
            'comment'  => 'Получено',
        ])->assertOk();

        $this->assertSame(RequestPartStatus::Received, $part->refresh()->status);
    }

    public function test_status_rejects_invalid_transition(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'proposed', null);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/status', [
            'revision' => $request['revision'],
            'status'   => 'ordered',
            'comment'  => 'test',
        ])->assertUnprocessable();
    }

    public function test_status_rejects_empty_comment(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'approved', 1);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/status', [
            'revision' => $request['revision'],
            'status'   => 'ordered',
            'comment'  => '',
        ])->assertUnprocessable();
    }

    /* ─────────────────── movement (station, stage=7) ─────────────────── */

    public function test_movement_return_partial(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'ordered', 1);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/movements', [
            'revision'          => $request['revision'],
            'kind'              => 'return',
            'qty'               => 1,
            'comment'           => 'Возврат части',
            'replacementPartId' => '',
            'attachmentIds'     => [],
        ])->assertOk();

        $part->refresh();
        $this->assertSame('1.000', $part->returned_quantity);
        $this->assertSame(RequestPartStatus::Ordered, $part->status);
    }

    public function test_movement_full_return_sets_status_returned(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'ordered', 1, quantity: '2.000');

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/movements', [
            'revision'          => $request['revision'],
            'kind'              => 'return',
            'qty'               => 2,
            'comment'           => 'Полный возврат',
            'replacementPartId' => '',
            'attachmentIds'     => [],
        ])->assertOk();

        $part->refresh();
        $this->assertSame('2.000', $part->returned_quantity);
        $this->assertSame(RequestPartStatus::Returned, $part->status);
    }

    public function test_movement_rejects_qty_over_remaining(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'ordered', 1, quantity: '2.000');

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/movements', [
            'revision'          => $request['revision'],
            'kind'              => 'return',
            'qty'               => 3,
            'comment'           => 'Слишком много',
            'replacementPartId' => '',
            'attachmentIds'     => [],
        ])->assertUnprocessable();
    }

    public function test_movement_exchange_requires_replacement(): void
    {
        $request = $this->requestAtReadyToRepair();
        $part = $this->makePart($request, 'ordered', 1);

        $this->actingAs($this->station);
        $this->postJson('/api/requests/' . $request['id'] . '/parts/' . $part->uuid . '/movements', [
            'revision'          => $request['revision'],
            'kind'              => 'exchange',
            'qty'               => 1,
            'comment'           => 'Обмен',
            'replacementPartId' => '',
            'attachmentIds'     => [],
        ])->assertUnprocessable();
    }

    /* ─────────────────── helpers ─────────────────── */

    /**
     * Заявка на этапе Review (1) — manager owner, есть одна работа.
     *
     * @return array<string, mixed>
     */
    private function requestAtReview(): array
    {
        $model = $this->makeRequest(self::STAGE_REVIEW);

        return [
            'id'              => $model->uuid,
            'systemId'        => $model->id,
            'revision'        => 1,
            'estimateVersion' => 1,
            'stage'           => self::STAGE_REVIEW,
        ];
    }

    /**
     * Заявка на этапе согласования СТО (2).
     *
     * @return array<string, mixed>
     */
    private function requestAtStationApproval(): array
    {
        $model = $this->makeRequest(self::STAGE_STATION_APPROVAL);

        return [
            'id'              => $model->uuid,
            'systemId'        => $model->id,
            'revision'        => 1,
            'estimateVersion' => 1,
            'stage'           => self::STAGE_STATION_APPROVAL,
        ];
    }

    /**
     * Заявка на этапе RepairInProgress (5) — station owner.
     *
     * @return array<string, mixed>
     */
    private function requestAtReadyToRepair(): array
    {
        $model = $this->makeRequest(self::STAGE_REPAIR_IN_PROGRESS);

        return [
            'id'              => $model->uuid,
            'systemId'        => $model->id,
            'revision'        => 1,
            'estimateVersion' => 1,
            'stage'           => self::STAGE_REPAIR_IN_PROGRESS,
        ];
    }

    private function makeRequest(int $stage): RepairRequest
    {
        $uuid = (string) Str::uuid();

        $model = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'vehicle_id'               => $this->vehicle->id,
            'assigned_station_id'      => $this->stationRecord->id,
            'data'                     => array_replace(
                array_fill_keys(Workflow::TEXT_FIELDS, ''),
                ['mileage' => '15000', 'slot' => '2026-10-20T11:00'],
            ),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => $stage,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
            'estimate_version'         => 1,
            'planned_completion_at'    => now()->addDay(),
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $model->id,
            'stage'             => $stage,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->customer->id,
        ]);

        RequestWork::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $model->id,
            'name'              => 'Диагностика',
            'quantity'          => 1,
            'price'             => 100,
            'is_maintenance'    => false,
        ]);

        return $model;
    }

    private function makePart(
        array $request,
        string $status = 'proposed',
        ?int $approvedVersion = null,
        string $quantity = '2.000',
        string $returned = '0.000',
    ): RequestPart {
        return RequestPart::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request['systemId'],
            'name'              => 'Фильтр',
            'article'           => 'F-1',
            'unit'              => 'шт.',
            'quantity'          => $quantity,
            'price'             => 500,
            'returned_quantity' => $returned,
            'status'            => $status,
            'approved_version'  => $approvedVersion,
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
}
