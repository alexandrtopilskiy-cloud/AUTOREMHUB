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
use Illuminate\Support\Str;
use Tests\TestCase;

final class VehicleAcceptanceActTest extends TestCase
{
    use DatabaseTransactions;

    public function test_station_creates_immutable_electronic_act_and_starts_work(): void
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
        $response = $this->postJson(
            '/api/requests/' . $request->uuid . '/acceptance-act',
            [
                'revision'     => 0,
                'mode'         => 'electronic',
                'mileage'      => 15010,
                'fuelLevel'    => 'half',
                'condition'    => 'Комплектность проверена',
                'defectTypes'  => ['scratch'],
                'diagramMarks' => [[
                    'zone'    => 'left',
                    'type'    => 'scratch',
                    'comment' => 'Передняя дверь',
                ]],
            ],
        )->assertOk();

        $response->assertJsonPath('request.stage', Workflow::STAGE_REPAIR_IN_PROGRESS);
        $response->assertJsonPath('request.acceptanceAct.number', 'AP-' . $request->number);
        $response->assertJsonPath('request.acceptanceAct.immutable', true);
        $this->assertDatabaseHas('vehicle_acceptance_acts', [
            'repair_request_id' => $request->id,
            'mileage'           => 15010,
            'fuel_level'        => 'half',
        ]);
        $this->assertGreaterThanOrEqual(
            2,
            RequestNotification::query()
                ->where('repair_request_id', $request->id)
                ->where('kind', 'work_started')
                ->count(),
        );

        $this->postJson(
            '/api/requests/' . $request->uuid . '/acceptance-act',
            [
                'revision'  => 1,
                'mode'      => 'electronic',
                'mileage'   => 15011,
                'fuelLevel' => 'full',
                'condition' => 'Повтор',
            ],
        )->assertForbidden();
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
