<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestNotification;
use App\Models\RequestStatusPeriod;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VehicleAcceptanceActTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Storage::fake('local');
    }

    public function test_station_uploads_signed_sheet_then_moves_to_work_by_next(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        $customerOrganization = $this->organization($tenant, 'customer', 'Заказчик');
        $managerOrganization = $this->organization($tenant, 'manager', 'УК');
        $stationOrganization = $this->organization($tenant, 'station', 'СТО');
        $customer = $this->user($customerOrganization, 'customer');
        $this->user($managerOrganization, 'manager');
        $stationUser = $this->user($stationOrganization, 'station');

        $station = Station::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $stationOrganization->id,
            'name'            => 'СТО',
            'address'         => 'Москва',
            'active'          => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'tenant_id'        => $tenant->id,
            'organization_id'  => $customerOrganization->id,
            'name'             => 'Lada Vesta',
            'make'             => 'Lada',
            'model'            => 'Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
            'labor_hour_rate'  => 1000,
        ]);

        $uuid = (string) Str::uuid();

        $request = RepairRequest::query()->create([
            'tenant_id'                => $tenant->id,
            'public_id'                => $uuid,
            'customer_organization_id' => $customerOrganization->id,
            'created_by'               => $customer->id,
            'vehicle_id'               => $vehicle->id,
            'assigned_station_id'      => $station->id,
            'data'                     => [
                'mileage'      => '15000',
                'slot'         => '2026-10-20T11:00',
                'stationSlot'  => '2026-10-20T11:00',
                'description'  => '',
                'contact'      => '',
                'routeComment' => '',
            ],
            'works'                    => [],
            'history'                  => [],
            'stage'                    => Workflow::STAGE_WAITING_VEHICLE,
            'revision'                 => 0,
            'review_context'           => 'initial',
            'review_baseline_version'  => 0,
        ])->refresh();
        RequestStatusPeriod::query()->create([
            'repair_request_id' => $request->id,
            'stage'             => Workflow::STAGE_WAITING_VEHICLE,
            'entered_at'        => now(),
            'actor_id'          => $customer->id,
        ]);

        $this->actingAs($stationUser);

        // Электронный акт больше не принимается: нужен подписанный лист.
        $this->postJson('/api/requests/' . $request->uuid . '/acceptance-act', [
            'revision'  => 0,
            'mode'      => 'electronic',
            'mileage'   => 15010,
            'fuelLevel' => 'half',
            'condition' => 'Комплектность проверена',
        ])->assertUnprocessable();

        // «Далее» без листа приёма-передачи — ошибка.
        $this->postJson('/api/requests/' . $request->uuid . '/next', ['revision' => 0, 'comment' => ''])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Загрузите подписанный лист приёма-передачи ТС в блок «Приём ТС от Заказчика на СТО»']);

        $uploaded = $this->postJson('/api/requests/' . $request->uuid . '/attachments', [
            'revision' => 0,
            'purpose'  => 'acceptance_act',
            'file'     => UploadedFile::fake()->image('list.png'),
        ])->assertSuccessful();
        $revision = (int) $uploaded->json('request.revision');
        $attachmentId = collect($uploaded->json('request.attachments'))
            ->firstWhere('purpose', 'acceptance_act')['id'];

        $response = $this->postJson('/api/requests/' . $request->uuid . '/acceptance-act', [
            'revision'     => $revision,
            'mode'         => 'uploaded',
            'mileage'      => 15010,
            'attachmentId' => $attachmentId,
        ])->assertOk();

        // Лист прикреплён, этап не меняется до «Далее по маршруту».
        $response->assertJsonPath('request.stage', Workflow::STAGE_WAITING_VEHICLE);
        $response->assertJsonPath('request.acceptanceAct.number', $request->numberWithPrefix('APP') . '/1');
        $response->assertJsonPath('request.acceptanceAct.immutable', true);
        $response->assertJsonPath('request.acceptanceAct.attachmentName', 'list.png');
        $response->assertJsonPath('request.permissions.canAdvance', true);
        $this->assertDatabaseHas('vehicle_acceptance_acts', [
            'repair_request_id' => $request->id,
            'mileage'           => 15010,
            'mode'              => 'uploaded',
        ]);

        $revision = (int) $response->json('request.revision');
        $this->postJson('/api/requests/' . $request->uuid . '/acceptance-act', [
            'revision'     => $revision,
            'mode'         => 'uploaded',
            'mileage'      => 15011,
            'attachmentId' => $attachmentId,
        ])->assertStatus(409);

        $this->postJson('/api/requests/' . $request->uuid . '/next', ['revision' => $revision, 'comment' => ''])
            ->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_REPAIR_IN_PROGRESS);

        $this->assertGreaterThanOrEqual(
            2,
            RequestNotification::query()
                ->where('repair_request_id', $request->id)
                ->where('kind', 'work_started')
                ->count(),
        );
    }

    public function test_handover_sheet_pdf_is_prefilled_for_both_directions(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Тестовая УК']);
        $customerOrganization = $this->organization($tenant, 'customer', 'ООО Заказчик');
        $stationOrganization = $this->organization($tenant, 'station', 'ООО СТО');
        $customer = $this->user($customerOrganization, 'customer');
        $station = Station::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $stationOrganization->id,
            'name'            => 'СТО',
            'address'         => 'Москва',
            'contact'         => 'Смирнов Алексей',
            'active'          => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'tenant_id'        => $tenant->id,
            'organization_id'  => $customerOrganization->id,
            'name'             => 'Kia Rio',
            'make'             => 'Kia',
            'model'            => 'Rio',
            'vin'              => 'Z94C241BBLR123456',
            'plate'            => 'А123ВС777',
            'plate_normalized' => 'А123ВС777',
            'year'             => 2020,
        ]);
        $uuid = (string) Str::uuid();
        $request = RepairRequest::query()->create([
            'tenant_id'                => $tenant->id,
            'public_id'                => $uuid,
            'customer_organization_id' => $customerOrganization->id,
            'created_by'               => $customer->id,
            'vehicle_id'               => $vehicle->id,
            'assigned_station_id'      => $station->id,
            'data'                     => ['mileage' => '1000'],
            'driver_snapshot'          => ['fullName' => 'Петров Иван', 'phone' => '+7 900'],
            'works'                    => [],
            'history'                  => [],
            'stage'                    => Workflow::STAGE_WAITING_VEHICLE,
            'revision'                 => 0,
            'review_context'           => 'initial',
            'review_baseline_version'  => 0,
        ])->refresh();

        $service = app(\App\Services\ClosingDocumentService::class);
        $in = $service->handoverSheet($request, 'in');
        $this->assertSame('in', $in['direction']);
        $this->assertSame($request->numberWithPrefix('APP') . '/1', $in['number']);
        $this->assertSame('ООО Заказчик', $in['customer']['name']);
        $this->assertSame('ООО СТО', $in['station']['name']);
        $this->assertSame('Петров Иван', $in['driver']['fullName']);
        $this->assertSame('Смирнов Алексей', $in['stationRepresentative']['fullName']);
        $this->assertSame(['name' => 'Kia Rio', 'plate' => 'А123ВС777', 'vin' => 'Z94C241BBLR123456', 'year' => 2020], $in['vehicle']);
        $this->assertSame(now()->toDateString(), $in['date']);
        $this->assertSame($request->numberWithPrefix('APP') . '/2', $service->handoverSheet($request, 'out')['number']);

        // Представитель СТО — пользователь ЛК СТО, который выгружает лист.
        $stationUser = $this->user($stationOrganization, 'station');
        $stationUser->forceFill(['last_name' => 'Соколов', 'first_name' => 'Максим', 'middle_name' => 'Николаевич'])->save();
        $this->assertSame(
            $stationUser->refresh()->fullName(),
            $service->handoverSheet($request, 'out', $stationUser)['stationRepresentative']['fullName'],
        );
        $this->assertStringContainsString('Соколов', $stationUser->fullName());
        // Для ЛК Заказчика/УК — контактное лицо СТО.
        $this->assertSame('Смирнов Алексей', $service->handoverSheet($request, 'in', $customer)['stationRepresentative']['fullName']);

        $this->actingAs($customer);
        foreach (['in', 'out'] as $direction) {
            $response = $this->get('/api/requests/' . $request->uuid . '/handover-sheet/' . $direction)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }
        $this->get('/api/requests/' . $request->uuid . '/handover-sheet/other')->assertNotFound();
    }

    private function organization(Tenant $tenant, string $kind, string $name): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $tenant->id,
            'kind'      => $kind,
            'name'      => $name,
        ]);
    }

    private function user(Organization $organization, string $role): User
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
