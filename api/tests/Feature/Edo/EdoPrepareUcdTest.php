<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EdoPrepareUcdTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $station;
    private Organization $manager;
    private User $user;
    private Station $stationEntity;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $key = EdoProviderEnum::SberKorus->value;

        config()->set('edo.default', $key);
        config()->set("edo.providers.{$key}.base_url", 'https://courier.example.test');
        config()->set("edo.providers.{$key}.username", 'test_login');
        config()->set("edo.providers.{$key}.password", 'test_password');

        $this->tenant = Tenant::factory()->create();

        $this->station = Organization::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'kind'          => 'station',
            'inn'           => '7701234567',
            'edo_client_id' => '12345',
            'edo_edi_code'  => '2BK-7701234567-12345',
        ]);

        $this->stationEntity = Station::factory()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->station->id,
        ]);

        $this->manager = Organization::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'kind'           => 'manager',
            'inn'            => '7709876543',
            'edo_client_id'  => '54321',
            'edo_edi_code'   => '2BK-7709876543-54321',
            'markup_percent' => 10.00,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->manager->id,
            'role'            => 'manager',
        ]);
    }

    public function test_prepares_ucd_from_base_upd(): void
    {
        $request = $this->makeRepairRequest();

        $baseDocument = $this->makeBaseUpd($request, [
            [
                'name'      => 'Работа А',
                'unit'      => '018',
                'amount'    => '2.00',
                'price'     => '1000.00',
                'net_sum'   => '1666.67',
                'vat_sum'   => '333.33',
                'total_sum' => '2000.00',
                'vat_type'  => '20%',
            ],
            [
                'name'      => 'Запчасть Б',
                'unit'      => '796',
                'amount'    => '1.00',
                'price'     => '500.00',
                'net_sum'   => '416.67',
                'vat_sum'   => '83.33',
                'total_sum' => '500.00',
                'vat_type'  => '20%',
            ],
        ]);

        Http::fake([
            'courier.example.test/api/auth/logon'                 => Http::response(['Token' => str_repeat('A', 100)], 200),
            'courier.example.test/api/document/createucddocument' => Http::response([
                'Filename' => 'ucd.xml',
                'MimeType' => 'text/xml',
                'Content'  => base64_encode('<xml/>'),
            ], 200),
            'courier.example.test/api/document/add/*'             => Http::response([
                'Id'   => '99999',
                'Uuid' => '11111111-2222-3333-4444-555555555555',
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/requests/{$request->uuid}/edo/prepare-ucd", [
                'base_document_uuid' => $baseDocument->uuid,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('edo_documents', [
            'tenant_id'     => $this->tenant->id,
            'document_type' => EdoDocumentType::UcdCsf->value,
            'status'        => EdoDocumentStatus::Draft->value,
            'external_id'   => '99999',
        ]);

        $ucd = EdoDocument::query()
            ->where('document_type', EdoDocumentType::UcdCsf->value)
            ->firstOrFail();

        $this->assertSame($baseDocument->id, $ucd->meta['base_edo_document_id']);
        $this->assertSame($baseDocument->uuid, $ucd->meta['base_edo_document_uuid']);
    }

    public function test_rejects_when_base_not_linked_to_request(): void
    {
        $request = $this->makeRepairRequest();
        $otherRequest = $this->makeRepairRequest();

        $baseDocument = $this->makeBaseUpd($otherRequest, [[
            'name'      => 'X',
            'unit'      => '018',
            'amount'    => '1.00',
            'price'     => '100.00',
            'net_sum'   => '83.33',
            'vat_sum'   => '16.67',
            'total_sum' => '100.00',
            'vat_type'  => '20%',
        ]]);

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/requests/{$request->uuid}/edo/prepare-ucd", [
                'base_document_uuid' => $baseDocument->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_UCD_BASE_NOT_FOUND');

        Http::assertNothingSent();
    }

    public function test_rejects_when_base_is_not_upd(): void
    {
        $request = $this->makeRepairRequest();

        $baseDocument = EdoDocument::factory()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->station->id,
            'counterparty_id' => $this->manager->id,
            'document_type'   => EdoDocumentType::ActWorks->value,
            'status'          => EdoDocumentStatus::Sended->value,
            'provider'        => EdoProviderEnum::SberKorus->value,
            'external_id'     => '111',
            'number'          => 'АКТ-1',
            'document_date'   => now()->toDateString(),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
        ]);

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/requests/{$request->uuid}/edo/prepare-ucd", [
                'base_document_uuid' => $baseDocument->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_UCD_BASE_WRONG_TYPE');

        Http::assertNothingSent();
    }

    public function test_rejects_when_no_snapshot(): void
    {
        $request = $this->makeRepairRequest();

        $baseDocument = EdoDocument::factory()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->station->id,
            'counterparty_id' => $this->manager->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended->value,
            'provider'        => EdoProviderEnum::SberKorus->value,
            'external_id'     => '111',
            'number'          => 'УПД-1',
            'document_date'   => now()->toDateString(),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => [],
        ]);

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/requests/{$request->uuid}/edo/prepare-ucd", [
                'base_document_uuid' => $baseDocument->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_UCD_NO_SNAPSHOT');

        Http::assertNothingSent();
    }

    public function test_rejects_when_base_is_rejected(): void
    {
        $request = $this->makeRepairRequest();

        $baseDocument = $this->makeBaseUpd($request, [[
            'name'      => 'X',
            'unit'      => '018',
            'amount'    => '1.00',
            'price'     => '100.00',
            'net_sum'   => '83.33',
            'vat_sum'   => '16.67',
            'total_sum' => '100.00',
            'vat_type'  => '20%',
        ]], ['status' => EdoDocumentStatus::Rejected->value]);

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/requests/{$request->uuid}/edo/prepare-ucd", [
                'base_document_uuid' => $baseDocument->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_UCD_BASE_FINALIZED');

        Http::assertNothingSent();
    }

    public function test_rejects_missing_base_uuid(): void
    {
        $request = $this->makeRepairRequest();

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/requests/{$request->uuid}/edo/prepare-ucd", []);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        Http::assertNothingSent();
    }

    private function makeRepairRequest(): RepairRequest
    {
        $request = RepairRequest::factory()->create([
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->station->id,
            'assigned_station_id'      => $this->stationEntity->id,
            'created_by'               => $this->user->id,
            'stage'                    => 1,
        ]);

        RequestStatusPeriod::factory()->create([
            'repair_request_id' => $request->id,
            'exited_at'         => null,
        ]);

        return $request;
    }

    /**
     * @param array<int, array<string, string>> $items
     */
    private function makeBaseUpd(
        RepairRequest $request,
        array $items,
        array $overrides = [],
    ): EdoDocument {
        return EdoDocument::factory()->create(array_merge([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->station->id,
            'counterparty_id' => $this->manager->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended->value,
            'provider'        => EdoProviderEnum::SberKorus->value,
            'external_id'     => '888888',
            'number'          => 'УПД-1',
            'document_date'   => now()->toDateString(),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => ['items_snapshot' => $items],
        ], $overrides));
    }
}
