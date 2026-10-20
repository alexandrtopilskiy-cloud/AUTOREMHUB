<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\UserRole;
use App\Enums\WorkOrderItemKind;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Edo\SberKorusResponseFactory;
use Tests\TestCase;

final class EdoPrepareManagerInvoiceRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_returns_prepared_document(): void
    {
        config()->set('edo.default', 'sberkorus');

        $tenant = Tenant::factory()->create();
        $tenantId = $tenant->id;

        $manager = Organization::factory()->create([
            'tenant_id'      => $tenantId,
            'kind'           => 'manager',
            'markup_percent' => 10.00,
            'edo_client_id'  => '2BM-0000000001',
            'edo_edi_code'   => '2BM-0000000001',
        ]);

        $customer = Organization::factory()->create([
            'tenant_id'     => $tenantId,
            'kind'          => 'customer',
            'edo_client_id' => '2BM-0000000002',
            'edo_edi_code'  => '2BM-0000000002',
        ]);

        $user = User::factory()->create([
            'organization_id' => $manager->id,
            'role'            => UserRole::Manager,
        ]);

        $vehicle = Vehicle::factory()->create([
            'tenant_id'       => $tenantId,
            'organization_id' => $customer->id,
        ]);

        $repairRequest = RepairRequest::factory()->create([
            'tenant_id'                => $tenantId,
            'customer_organization_id' => $customer->id,
            'vehicle_id'               => $vehicle->id,
            'created_by'               => $user->id,
        ]);

        $station = Station::factory()->create([
            'tenant_id'       => $tenantId,
            'organization_id' => $manager->id,
        ]);

        RequestStatusPeriod::factory()->create([
            'repair_request_id' => $repairRequest->id,
            'exited_at'         => null,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'repair_request_id' => $repairRequest->id,
            'vehicle_id'        => $vehicle->id,
            'station_id'        => $station->id,
        ]);

        WorkOrderItem::factory()->create([
            'work_order_id' => $workOrder->id,
            'kind'          => WorkOrderItemKind::Work,
            'name'          => 'Работа',
            'quantity'      => 1.000,
            'price'         => 1000.00,
        ]);

        Http::fake([
            '*/api/auth/logon' => SberKorusResponseFactory::logon('test-token'),

            '*/v2.0/client/self' => SberKorusResponseFactory::selfCompany([
                'Id'     => '2BM-0000000001',
                'Inn'    => $manager->inn,
                'Kpp'    => $manager->kpp,
                'Name'   => $manager->name,
                'Code'   => '2BM-0000000001',
            ]),

            '*/api/document/createinvoice/*' => Http::response([
                'Filename' => 'invoice.xml',
                'MimeType' => 'text/xml',
                'Content'  => base64_encode('<xml>invoice</xml>'),
            ], 200, ['Content-Type' => 'application/json']),

            '*/api/document/add/*' => Http::response([
                'Id'   => 'ext-1',
                'Uuid' => '00000000-0000-0000-0000-000000000001',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

$this->withoutExceptionHandling();
        $response = $this->actingAs($user)
            ->postJson("/api/requests/{$repairRequest->uuid}/edo/prepare-manager-invoice", [
            'document_type' => 'upd_sf',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'uuid',
                'payloadBase64',
                'payloadFilename',
                'signatureHint',
            ],
        ]);
    }
}
