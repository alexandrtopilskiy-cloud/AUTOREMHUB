<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Edo\SberKorusResponseFactory as F;
use Tests\TestCase;

final class EdoPrepareSignAndSendFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_then_sign_and_send_end_to_end(): void
    {
        [$tenant, $manager, $stationOrg, $counterparty, $user, $request] = $this->seedEnvironment();

        Http::fake(F::fakeMap());

        // --- 1. PREPARE ---------------------------------------------------
        $prepare = $this->actingAs($user)->postJson(
            "/api/requests/{$request->uuid}/edo/prepare",
            [
                'document_type'                => EdoDocumentType::UpdSf->value,
                'counterparty_organization_id' => $counterparty->id,
            ],
        );

        $prepare->assertCreated();

        $uuid = $prepare->json('data.uuid');
        $this->assertNotEmpty($uuid);

        /** @var EdoDocument $document */
        $document = EdoDocument::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame($tenant->id, $document->tenant_id);
        $this->assertSame($stationOrg->id, $document->organization_id);
        $this->assertSame($counterparty->id, $document->counterparty_id);
        $this->assertSame(EdoDocumentType::UpdSf, $document->document_type);
        $this->assertSame(EdoDocumentStatus::Draft, $document->status);
        $this->assertSame(RepairRequest::class, $document->source_type);
        $this->assertSame($request->id, $document->source_id);

        $cmsBase64 = base64_encode(str_repeat('A', 128));

        $send = $this->actingAs($user)->postJson(
            "/api/edo/documents/{$uuid}/sign-and-send",
            ['signature_base64' => $cmsBase64],
        );

        $send->assertOk();

        // --- 4. Проверяем Http-вызовы -------------------------------------
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/document/sign/'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/document/send/'));

        Http::assertSent(function (Request $r) use ($cmsBase64) {
            if (! str_contains($r->url(), '/api/document/sign/')) {
                return false;
            }
            $body = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, $cmsBase64);
        });

        // --- 5. Состояние документа после sign-and-send -------------------
        $document->refresh();
        $this->assertSame(EdoDocumentStatus::Sended, $document->status);
    }

    public function test_sign_and_send_requires_prepared_status(): void
    {
        [$tenant, $manager, $stationOrg, $counterparty, $user, $request] = $this->seedEnvironment();

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $stationOrg->id,
            'counterparty_id' => $counterparty->id,
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft,
        ]);

        Http::fake(F::fakeMap());

        $response = $this->actingAs($user)->postJson(
            "/api/edo/documents/{$document->uuid}/sign-and-send",
            ['signature_base64' => base64_encode(str_repeat('A', 128))],
        );

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

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

        $vehicle = \App\Models\Vehicle::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $manager->id,
        ]);

        $station = \App\Models\Station::factory()->create([
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

        \App\Models\WorkOrder::factory()->create([
            'repair_request_id' => $request->id,
            'vehicle_id'        => $vehicle->id,
            'station_id'        => $station->id,
            'estimate_version'  => $request->estimate_version,
        ]);

        \App\Models\RequestStatusPeriod::factory()->create([
            'repair_request_id' => $request->id,
            'actor_id'          => $user->id,
            'stage'             => 1,
            'entered_at'        => now()->subHour(),
            'exited_at'         => null,
        ]);

        return [$tenant, $manager, $stationOrg, $counterparty, $user, $request];
    }
}