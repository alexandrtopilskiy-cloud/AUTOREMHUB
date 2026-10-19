<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\RepairStage;
use App\Enums\TransitionAction;
use App\Exceptions\ApiException;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\Recommendation;
use App\Models\RepairRequest;
use App\Models\RequestPart;
use App\Models\RequestStatusPeriod;
use App\Models\RequestNotification;
use App\Models\RequestWork;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RecommendationService;
use App\Services\RepairRequestService;
use App\Services\VehicleReferenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecommendationAndEstimateTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;
    private User $customer;
    private User $station;
    private Vehicle $vehicle;
    private RepairRequest $request;
    private Supplier $supplier;
    private CatalogPart $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::query()->create(['name' => 'Recommendations test']);
        foreach (['manager', 'customer', 'station'] as $role) {
            $org = Organization::query()->create(['tenant_id' => $tenant->id, 'kind' => $role, 'name' => $role]);
            $this->{$role} = User::query()->create(['organization_id' => $org->id, 'display_name' => 'Иван ' . $role, 'login' => $role . Str::random(8), 'password' => bcrypt('test'), 'role' => $role, 'active' => true]);
        }
        $this->vehicle = Vehicle::query()->create(['tenant_id' => $tenant->id, 'organization_id' => $this->customer->organization_id, 'name' => 'Test Car', 'make' => 'Test', 'model' => 'Car', 'vin' => 'TESTVIN1234567890', 'plate' => 'A111AA78', 'plate_normalized' => 'A111AA78', 'labor_hour_rate' => 1000]);
        $station = Station::query()->create(['tenant_id' => $tenant->id, 'organization_id' => $this->station->organization_id, 'name' => 'Station']);
        $this->request = RepairRequest::query()->create(['public_id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'customer_organization_id' => $this->customer->organization_id, 'created_by' => $this->customer->id, 'vehicle_id' => $this->vehicle->id, 'assigned_station_id' => $station->id, 'stage' => RepairStage::ManagerRouting, 'revision' => 0, 'data' => ['mileage' => '1000', 'laborRateType' => 'general'], 'works' => [], 'history' => []]);
        RequestStatusPeriod::query()->create(['repair_request_id' => $this->request->id, 'stage' => 1, 'entered_at' => now(), 'actor_id' => $this->manager->id]);
        $this->supplier = Supplier::query()->create(['tenant_id' => $tenant->id, 'name' => 'Supplier', 'active' => true]);
        $this->catalog = CatalogPart::query()->create(['tenant_id' => $tenant->id, 'name' => 'Filter', 'article' => 'F-1', 'unit' => 'шт.', 'manufacturer' => 'Test', 'supplier_id' => $this->supplier->id, 'sale_price' => 200, 'active' => true]);
    }

    private function work(): RequestWork
    {
        return RequestWork::query()->create(['repair_request_id' => $this->request->id, 'name' => 'Двигатель - замена масла', 'quantity' => 1, 'price' => 1500, 'hourly_rate' => 1000, 'norm_hours' => 1.5, 'line_amount' => 1500, 'is_maintenance' => true]);
    }

    private function part(): RequestPart
    {
        return RequestPart::query()->create(['repair_request_id' => $this->request->id, 'catalog_part_id' => $this->catalog->id, 'name' => 'Filter', 'article' => 'F-1', 'unit' => 'шт.', 'supplier_id' => $this->supplier->id, 'quantity' => 2, 'price' => 250, 'status' => 'proposed']);
    }

    private function partPayload(): array
    {
        return ['id' => '', 'catalogPartId' => $this->catalog->uuid, 'name' => 'Filter', 'article' => 'F-1', 'unit' => 'шт.', 'supplierId' => $this->supplier->uuid, 'qty' => 2, 'price' => 300];
    }

    public function test_common_save_persists_parts_and_request_fields_once(): void
    {
        $result = app(RepairRequestService::class)->update($this->manager, $this->request->uuid, 0, ['routeComment' => 'test', 'parts' => [$this->partPayload()]]);
        $this->assertSame(1, $result->revision);
        $this->assertSame('test', $result->data['routeComment']);
        $this->assertSame('300.00', $result->parts()->first()->price);
        $this->assertSame('200.00', $this->catalog->fresh()->sale_price);
    }

    public function test_invalid_part_rolls_back_entire_common_save(): void
    {
        $invalid = $this->partPayload();
        $invalid['supplierId'] = Str::uuid()->toString();
        try {
            app(RepairRequestService::class)->update($this->manager, $this->request->uuid, 0, ['routeComment' => 'unsaved', 'parts' => [$this->partPayload(), $invalid]]);
            $this->fail('Invalid supplier must fail');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, $this->request->fresh()->revision);
            $this->assertSame(0, $this->request->parts()->count());
            $this->assertArrayNotHasKey('routeComment', $this->request->fresh()->data);
        }
    }

    public function test_defer_keeps_all_snapshots_and_restores_once(): void
    {
        $work = $this->work();
        $part = $this->part();
        $service = app(RecommendationService::class);
        $result = $service->defer($this->manager, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => [$part->uuid]]);
        $doc = $result['recommendation'];
        $this->assertStringStartsWith('REC-', $doc['number']);
        $this->assertSame($this->vehicle->vin, $doc['vin']);
        $this->assertCount(2, $doc['items']);
        $this->assertStringEndsWith('(У)', $doc['items'][0]['actor']);
        $this->assertSame(0, $this->request->workLines()->count());
        $this->assertSame(0, $this->request->parts()->count());
        $restored = $service->restore($this->manager, $doc['id'], 0, array_column($doc['items'], 'id'), $this->request->uuid, 1);
        $this->assertSame(1500.0, $restored['request']['works'][0]['lineAmount']);
        $this->assertSame(250.0, $restored['request']['parts'][0]['price']);
        $this->assertNotNull($restored['recommendation']['items'][0]['movedAt']);
        $this->expectException(ApiException::class);
        $service->restore($this->manager, $doc['id'], 0, array_column($doc['items'], 'id'), $this->request->uuid, 2);
    }

    public function test_customer_can_defer_only_at_final_approval(): void
    {
        $work = $this->work();
        $service = app(RecommendationService::class);
        try {
            $service->defer($this->customer, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []]);
            $this->fail('Read-only customer must not defer');
        } catch (ApiException $exception) {
            $this->assertSame(403, $exception->status);
        }
        $this->request->update(['stage' => RepairStage::CustomerApproval]);
        $result = $service->defer($this->customer, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []]);
        $this->assertSame(3, $result['request']['stage']);
        $this->assertStringEndsWith('(З)', $result['recommendation']['items'][0]['actor']);
    }

    public function test_station_creates_new_request_from_recommendations_with_creator_permissions(): void
    {
        $this->request->update(['stage' => RepairStage::StationApproval]);
        $work = $this->work();
        $service = app(RecommendationService::class);
        $doc = $service->defer($this->station, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []])['recommendation'];
        $result = $service->restore($this->station, $doc['id'], 0, array_column($doc['items'], 'id'), null, null);
        $this->assertSame(0, $result['request']['stage']);
        $this->assertTrue($result['request']['permissions']['canAdvance']);
        $this->assertSame($this->vehicle->uuid, $result['request']['vehicleId']);
        $this->assertCount(1, $result['request']['works']);
        $this->assertSame('1000', $result['request']['mileage']);
        $this->assertNotContains('mileage', $result['request']['permissions']['editableFields']);
        $this->assertNotContains('vehicleId', $result['request']['permissions']['editableFields']);
        $contact = \App\Models\VehicleContact::query()->create(['vehicle_id' => $this->vehicle->id, 'kind' => 'contact', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => '+79991234567', 'active' => true]);
        $reason = \App\Models\ServiceReason::query()->create(['tenant_id' => $this->vehicle->tenant_id, 'name' => 'Диагностика', 'code' => 'diagnostics', 'active' => true]);
        $draft = app(RepairRequestService::class)->update($this->station, $result['request']['id'], $result['request']['revision'], ['contactId' => $contact->uuid, 'reasonId' => $reason->uuid]);
        $routed = app(RepairRequestService::class)->transition($this->station, $draft->uuid, $draft->revision, TransitionAction::Next, ['comment' => 'Новая заявка из рекомендаций']);
        $this->assertSame(RepairStage::ManagerRouting, $routed->stage);
        $this->assertSame($routed->id, RepairRequest::resolveFor($this->manager, $routed->uuid)->id);
    }

    public function test_different_vin_and_foreign_tenant_are_rejected_without_moving_items(): void
    {
        $work = $this->work();
        $service = app(RecommendationService::class);
        $doc = $service->defer($this->manager, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []])['recommendation'];
        $this->request->update(['vehicle_id' => null]);
        try {
            $service->restore($this->manager, $doc['id'], 0, array_column($doc['items'], 'id'), $this->request->uuid, 1);
            $this->fail('Different VIN must fail');
        } catch (ApiException) {
            $this->assertNull(Recommendation::query()->where('uuid', $doc['id'])->first()->items()->first()->moved_at);
        }
        $other = Tenant::query()->create(['name' => 'Other tenant']);
        $org = Organization::query()->create(['tenant_id' => $other->id, 'kind' => 'manager', 'name' => 'Other']);
        $user = User::query()->create(['organization_id' => $org->id, 'login' => Str::random(20), 'display_name' => 'Other', 'password' => bcrypt('test'), 'role' => 'manager', 'active' => true]);
        $this->assertSame([], $service->forVehicle($user, $this->vehicle->uuid));
    }

    public function test_ordered_part_cannot_be_deferred(): void
    {
        $part = $this->part();
        $part->update(['status' => 'ordered']);
        $this->expectException(ApiException::class);
        app(RecommendationService::class)->defer($this->manager, $this->request->uuid, 0, ['workIds' => [], 'partIds' => [$part->uuid]]);
    }

    public function test_vehicle_catalog_duplicate_considers_complete_variant(): void
    {
        $payload = ['make' => 'TestMake', 'model' => 'TestModel', 'bodyCode' => 'G1', 'engineIndex' => 'E1', 'engineType' => 'Бензин', 'horsepower' => 100.0, 'engineVolumeLiters' => 1.6, 'transmission' => 'АКПП', 'driveType' => 'передний'];
        $service = app(VehicleReferenceService::class);
        $first = $service->create($this->manager, $payload, true);
        $second = $service->create($this->manager, array_replace($payload, ['engineIndex' => 'E2']), true);
        $this->assertNotSame($first['id'], $second['id']);
        $this->expectException(ApiException::class);
        $service->create($this->manager, $payload, true);
    }
    public function test_http_common_save_and_recommendation_routes(): void
    {
        $this->actingAs($this->manager);
        $saved = $this->patchJson('/api/requests/' . $this->request->uuid, ['revision' => 0, 'patch' => ['parts' => [$this->partPayload()]]])->assertOk()->json('request');
        $part = $saved['parts'][0];
        $deferred = $this->postJson('/api/requests/' . $this->request->uuid . '/recommendations', ['revision' => 1, 'workIds' => [], 'partIds' => [$part['id']]])->assertOk()->json('recommendation');
        $this->getJson('/api/recommendations?vehicleId=' . $this->vehicle->uuid)->assertOk()->assertJsonPath('recommendations.0.number', $deferred['number']);
        $this->postJson('/api/recommendations/' . $deferred['id'] . '/restore', ['revision' => 0, 'itemIds' => [$deferred['items'][0]['id']], 'requestId' => $this->request->uuid, 'requestRevision' => 2])->assertOk()->assertJsonPath('request.parts.0.price', 300);
    }

    public function test_customer_restoration_requires_new_manager_approval(): void
    {
        $work = $this->work();
        $service = app(RecommendationService::class);
        $doc = $service->defer($this->manager, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []])['recommendation'];
        $this->request->update(['stage' => RepairStage::CustomerApproval]);
        $result = $service->restore($this->customer, $doc['id'], 0, [$doc['items'][0]['id']], $this->request->uuid, 1);
        $this->assertSame(1, $result['request']['stage']);
        $this->assertFalse($result['request']['permissions']['canRestoreRecommendations']);
        $this->assertTrue(RequestNotification::query()->where('repair_request_id', $this->request->id)->where('user_id', $this->manager->id)->where('kind', 'manager_review_required')->exists());
    }

    public function test_read_only_user_cannot_restore_into_a_request(): void
    {
        $work = $this->work();
        $service = app(RecommendationService::class);
        $doc = $service->defer($this->manager, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []])['recommendation'];
        $this->expectException(ApiException::class);
        $service->restore($this->customer, $doc['id'], 0, [$doc['items'][0]['id']], $this->request->uuid, 1);
    }

    public function test_catalog_manual_creation_is_manager_only_and_rejects_exact_duplicates(): void
    {
        $payload = ['make' => 'TestMake', 'model' => 'TestModel', 'bodyCode' => 'G2'];
        $this->actingAs($this->customer)->postJson('/api/vehicle-reference/catalog', ['record' => $payload])->assertForbidden();
        $this->actingAs($this->manager)->postJson('/api/vehicle-reference/catalog', ['record' => $payload])->assertCreated();
        $this->postJson('/api/vehicle-reference/catalog', ['record' => $payload])->assertConflict()->assertJsonPath('error.code', 'VEHICLE_VARIANT_DUPLICATE');
    }
    public function test_changed_vehicle_vin_cannot_receive_old_recommendations(): void
    {
        $work = $this->work();
        $service = app(RecommendationService::class);
        $doc = $service->defer($this->manager, $this->request->uuid, 0, ['workIds' => [$work->uuid], 'partIds' => []])['recommendation'];
        $this->vehicle->update(['vin' => 'TESTOTHER12345678']);
        $this->expectException(ApiException::class);
        $service->restore($this->manager, $doc['id'], 0, [$doc['items'][0]['id']], $this->request->uuid, 1);
    }

}
