<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Attachment;
use App\Models\DiagnosticReport;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для DiagnosticController.
 *
 * Покрывает:
 *   - store: успех (station на stage 4), 201, audit attachments
 *   - 403: не station, не владелец stage 4
 *   - 422: пустой conclusion, отсутствующие attachmentIds
 *   - 422: attachmentIds с неправильным purpose (не diagnostic)
 *   - сохранение mileage из data
 */
final class DiagnosticControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGE_REPAIR_IN_PROGRESS = 5;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private User $station;
    private Vehicle $vehicle;
    private Station $stationRecord;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');

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

        $this->stationRecord = Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->stationOrg->id,
            'name'            => 'Тестовая СТО',
            'address'         => 'Москва',
        ]);
    }

    /* ─────────────────── store: успех ─────────────────── */

    public function test_store_creates_diagnostic_report(): void
    {
        $request = $this->requestAtVehicleAccepted();
        $file = $this->makeAttachment($request, 'diagnostic');

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Требуется замена масла',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertCreated();

        $report = DiagnosticReport::query()
        ->where('repair_request_id', $request['systemId'])
            ->firstOrFail();

        $this->assertSame('Требуется замена масла', $report->conclusion);
        $this->assertSame(15000, $report->mileage);
        $this->assertSame($this->stationRecord->id, $report->station_id);
        $this->assertSame($this->station->id, $report->author_id);
        $this->assertSame([$file->uuid], $report->attachment_ids);
    }

    public function test_store_records_mileage_from_request_data(): void
    {
        $request = $this->requestAtVehicleAccepted(mileage: '99999');
        $file = $this->makeAttachment($request, 'diagnostic');

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Ок',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertCreated();

        $report = DiagnosticReport::query()
        ->where('repair_request_id', $request['systemId'])
            ->firstOrFail();

        $this->assertSame(99999, $report->mileage);
    }

    public function test_store_links_attachments_to_event(): void
    {
        $request = $this->requestAtVehicleAccepted();
        $file = $this->makeAttachment($request, 'diagnostic');

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Ок',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertCreated();

        $this->assertNotNull($file->refresh()->request_event_id);
    }

    public function test_store_creates_request_event(): void
    {
        $request = $this->requestAtVehicleAccepted();
        $file = $this->makeAttachment($request, 'diagnostic');

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Ок',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertCreated();

        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $request['systemId'],
            'action'            => 'diagnosis',
        ]);
    }

    /* ─────────────────── store: 403 ─────────────────── */

    public function test_store_forbidden_for_manager(): void
    {
        $request = $this->requestAtVehicleAccepted();
        $file = $this->makeAttachment($request, 'diagnostic');

        $this->actingAs($this->manager);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Ок',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertForbidden();
    }

    public function test_store_forbidden_for_customer(): void
    {
        $request = $this->requestAtVehicleAccepted();
        $file = $this->makeAttachment($request, 'diagnostic');

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Ок',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertForbidden();
    }

    /* ─────────────────── store: 422 ─────────────────── */

    public function test_store_rejects_empty_conclusion(): void
    {
        $request = $this->requestAtVehicleAccepted();
        $file = $this->makeAttachment($request, 'diagnostic');

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => '',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_missing_attachment_ids(): void
    {
        $request = $this->requestAtVehicleAccepted();

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'   => $request['revision'],
                'conclusion' => 'Ок',
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_attachment_with_wrong_purpose(): void
    {
        $request = $this->requestAtVehicleAccepted();
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Ок',
                'attachmentIds' => [$file->uuid],
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_unknown_attachment(): void
    {
        $request = $this->requestAtVehicleAccepted();

        $this->actingAs($this->station);
        $this->postJson(
            '/api/requests/' . $request['id'] .'/diagnostics',
            [
                'revision'      => $request['revision'],
                'conclusion'    => 'Ок',
                'attachmentIds' => [(string) Str::uuid()],
            ],
        )->assertUnprocessable();
    }

    /* ─────────────────── helpers ─────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function requestAtVehicleAccepted(string $mileage = '15000'): array
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
                ['mileage' => $mileage, 'slot' => '2026-10-20T11:00'],
            ),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => self::STAGE_REPAIR_IN_PROGRESS,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
            'estimate_version'         => 1,
            'planned_completion_at'    => now()->addDay(),
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $model->id,
            'stage'             => self::STAGE_REPAIR_IN_PROGRESS,
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

        return [
            'id'              => $model->uuid,
            'systemId'        => $model->id,
            'revision'        => 1,
            'estimateVersion' => 1,
            'stage'           => self::STAGE_REPAIR_IN_PROGRESS,
        ];
    }

    private function makeAttachment(array $request, string $purpose): Attachment
    {
        $period = RequestStatusPeriod::query()
        ->where('repair_request_id', $request['systemId'])
            ->whereNull('exited_at')
            ->firstOrFail();

        return Attachment::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'repair_request_id'        => $request['systemId'],
            'uploaded_by'              => $this->station->id,
            'request_status_period_id' => $period->id,
            'purpose'                  => $purpose,
            'disk'                     => 'media',
            'object_key'               => 'test/' . Str::uuid() . '.png',
            'original_name'            => 'diag.png',
            'mime_type'                => 'image/png',
            'size'                     => 100,
            'sha256'                   => str_repeat('a', 64),
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
