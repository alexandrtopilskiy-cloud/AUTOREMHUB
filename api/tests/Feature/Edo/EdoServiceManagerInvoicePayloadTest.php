<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\WorkOrderItemKind;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\Edo\EdoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EdoServiceManagerInvoicePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_rows_applies_manager_markup_and_reverse_vat(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantId = $tenant->id;

        $manager = Organization::factory()->create([
            'tenant_id'      => $tenantId,
            'kind'           => 'manager',
            'markup_percent' => 10.00,
        ]);

        $customer = Organization::factory()->create([
            'tenant_id' => $tenantId,
            'kind'      => 'customer',
        ]);

        $vehicle = Vehicle::factory()->create([
            'tenant_id'       => $tenantId,
            'organization_id' => $customer->id,
        ]);

        $repairRequest = RepairRequest::factory()->create([
            'tenant_id'                => $tenantId,
            'customer_organization_id' => $customer->id,
            'vehicle_id'               => $vehicle->id,
        ]);

        $station = Station::factory()->create([
            'tenant_id'       => $tenantId,
            'organization_id' => $manager->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'repair_request_id' => $repairRequest->id,
            'vehicle_id'        => $vehicle->id,
            'station_id'        => $station->id,
        ]);

        WorkOrderItem::factory()->create([
            'work_order_id' => $workOrder->id,
            'kind'          => WorkOrderItemKind::Work,
            'name'          => 'Замена масла',
            'quantity'      => 1.000,
            'price'         => 1000.00,
        ]);

        WorkOrderItem::factory()->create([
            'work_order_id' => $workOrder->id,
            'kind'          => WorkOrderItemKind::Part,
            'name'          => 'Фильтр масляный',
            'quantity'      => 2.000,
            'price'         => 500.00,
        ]);

        /** @var EdoService $service */
        $service = app(EdoService::class);

        $payload = $service->buildInvoicePayloadForManagerToCustomer($repairRequest, $manager);

        $this->assertCount(2, $payload->rows);

        $row1 = $payload->rows[0];
        $this->assertSame('Замена масла', $row1->name);
        $this->assertSame('018', $row1->unit);
        $this->assertSame('1.00', $row1->amount);
        $this->assertSame('1100.00', $row1->price);
        $this->assertSame('1100.00', $row1->totalSum);
        $this->assertSame('916.67', $row1->netSum);
        $this->assertSame('183.33', $row1->vatSum);
        $this->assertSame('20%', $row1->vatType);

        $row2 = $payload->rows[1];
        $this->assertSame('Фильтр масляный', $row2->name);
        $this->assertSame('796', $row2->unit);
        $this->assertSame('2.00', $row2->amount);
        $this->assertSame('550.00', $row2->price);
        $this->assertSame('1100.00', $row2->totalSum);
        $this->assertSame('916.67', $row2->netSum);
        $this->assertSame('183.33', $row2->vatSum);
    }

    public function test_build_rows_uses_fallback_markup_from_config(): void
    {
        config()->set('edo.manager_markup_percent', 25.0);

        $tenant = Tenant::factory()->create();

        $manager = Organization::factory()->create([
            'tenant_id'      => $tenant->id,
            'kind'           => 'manager',
            'markup_percent' => null,
        ]);

        $this->assertSame(25.0, $manager->markupPercent());
    }
}
