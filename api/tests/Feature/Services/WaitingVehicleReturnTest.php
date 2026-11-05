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

/**
 * Этап 4 «Ожидание ТС на СТО»: СТО возвращает ЗР Заказчику на этап 3
 * «Согласование Заказчика», комментарий обязателен.
 */
final class WaitingVehicleReturnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_station_returns_request_to_customer_approval_with_comment(): void
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
        $this->getJson('/api/requests/' . $request->uuid)
            ->assertOk()
            ->assertJsonPath('request.permissions.canReturn', true)
            ->assertJsonPath('request.permissions.canAcceptVehicle', true);

        $this->postJson('/api/requests/' . $request->uuid . '/return', [
            'revision' => 0,
            'comment'  => '   ',
        ])->assertStatus(422);
        $this->assertSame(Workflow::STAGE_WAITING_VEHICLE, $request->fresh()->stage->value);

        $this->postJson('/api/requests/' . $request->uuid . '/return', [
            'revision' => 0,
            'comment'  => 'Подъёмник занят, согласуйте другое время',
        ])->assertOk()
            ->assertJsonPath('request.stage', Workflow::STAGE_CUSTOMER_APPROVAL);

        $this->assertSame(
            1,
            RequestNotification::query()
                ->where('repair_request_id', $request->id)
                ->where('kind', 'request_returned')
                ->where('user_id', $customer->id)
                ->count(),
        );

        $this->actingAs($customer);
        $this->getJson('/api/requests/' . $request->uuid)
            ->assertOk()
            ->assertJsonPath('request.permissions.canCustomerSlotDecide', true);
    }

    public function test_manager_and_customer_cannot_return_from_waiting_vehicle(): void
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

        foreach (User::query()->whereIn('role', ['manager', 'customer'])->whereIn('organization_id', [$customerOrganization->id, $managerOrganization->id])->get() as $user) {
            $this->actingAs($user);
            $this->postJson('/api/requests/' . $request->uuid . '/return', [
                'revision' => 0,
                'comment'  => 'Вернуть',
            ])->assertForbidden();
        }
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
