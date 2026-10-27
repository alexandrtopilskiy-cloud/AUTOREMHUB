<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EdoPrepareSignAndSendFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_rejects_foreign_counterparty(): void
    {
        [$tenant, $manager, $stationOrg, $counterparty, $user, $request] = $this->seedEnvironment();

        $foreignTenant = Tenant::factory()->create();
        $foreignCounterparty = Organization::factory()->create([
            'tenant_id' => $foreignTenant->id,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/requests/{$request->uuid}/edo/prepare",
            [
                'document_type'                => EdoDocumentType::UpdSf->value,
                'counterparty_organization_id' => $foreignCounterparty->id,
            ],
        );

        $this->assertContains($response->status(), [404, 422]);
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: Organization, 3: Organization, 4: User, 5: RepairRequest}
     *         [tenant, manager, stationOrg, counterparty, user, request]
     */
    private function seedEnvironment(): array
    {
        $tenant = Tenant::factory()->create();

        $manager = Organization::factory()->create([
            'tenant_id'         => $tenant->id,
            'kind'              => 'manager',
            'edo_provider'      => EdoProvider::SberKorus->value,
            'edo_client_id'     => 'client-123',
            'edo_edi_code'      => '2BM-1234567890',
            'edo_registered_at' => now(),
        ]);

        $stationOrg = Organization::factory()->create([
            'tenant_id'         => $tenant->id,
            'kind'              => 'station',
            'edo_provider'      => EdoProvider::SberKorus->value,
            'edo_client_id'     => 'client-station',
            'edo_edi_code'      => '2BM-9900000000',
            'edo_registered_at' => now(),
        ]);

        $counterparty = Organization::factory()->create([
            'tenant_id'         => $tenant->id,
            'kind'              => 'customer',
            'inn'               => '7701234567',
            'kpp'               => '770101001',
            'name'              => 'ООО "Заказчик"',
            'short_name'        => 'Заказчик',
            'edo_provider'      => EdoProvider::SberKorus->value,
            'edo_client_id'     => 'client-456',
            'edo_edi_code'      => '2BM-7701234567',
            'edo_registered_at' => now(),
        ]);

        $user = User::factory()->create([
            'organization_id' => $manager->id,
            'role'            => 'manager',
        ]);

        $vehicle = Vehicle::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $manager->id,
        ]);

        $station = Station::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $stationOrg->id,
        ]);

        $request = RepairRequest::factory()->create([
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $manager->id,
            'created_by'               => $user->id,
            'vehicle_id'               => $vehicle->id,
            'assigned_station_id'      => $station->id,
        ]);

        WorkOrder::factory()->create([
            'repair_request_id' => $request->id,
            'vehicle_id'        => $vehicle->id,
            'station_id'        => $station->id,
            'estimate_version'  => $request->estimate_version,
        ]);

        RequestStatusPeriod::factory()->create([
            'repair_request_id' => $request->id,
            'actor_id'          => $user->id,
            'stage'             => 1,
            'entered_at'        => now()->subHour(),
            'exited_at'         => null,
        ]);

        return [$tenant, $manager, $stationOrg, $counterparty, $user, $request];
    }
}