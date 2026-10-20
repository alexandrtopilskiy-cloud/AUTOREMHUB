<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Edo\SberKorusResponseFactory as F;
use Tests\TestCase;

final class EdoDocumentControllerSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_route_updates_document_status_from_provider(): void
    {
        [$tenant, $org, $counterparty, $user] = $this->seedEnvironment();

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended,
            'external_id'     => '161355',
            'last_synced_at'  => null,
        ]);

        Http::fake([
            '*/api/auth/logon'      => Http::response(F::logon(), 200),
            '*/api/document/card/*' => Http::response(F::documentCardSigned(), 200),
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/edo/documents/{$document->uuid}/sync",
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'signed');

        $document->refresh();
        $this->assertSame(EdoDocumentStatus::Signed, $document->status);
        $this->assertNotNull($document->signed_at);
        $this->assertNotNull($document->last_synced_at);
    }

    public function test_sync_rejects_document_without_external_id(): void
    {
        [$tenant, $org, $counterparty, $user] = $this->seedEnvironment();

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft,
            'external_id'     => null,
        ]);

        Http::fake(F::fakeMap());

        $response = $this->actingAs($user)->postJson(
            "/api/edo/documents/{$document->uuid}/sync",
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EDO_NO_EXTERNAL_ID');
        Http::assertNothingSent();
    }

    public function test_sync_returns_404_for_other_tenant(): void
    {
        [$tenant, $org, $counterparty, $user] = $this->seedEnvironment();

        $otherTenant = Tenant::factory()->create();
        $otherOrg = Organization::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended,
            'external_id'     => '161355',
        ]);

        Http::fake(F::fakeMap());

        $this->actingAs($otherUser)
            ->postJson("/api/edo/documents/{$document->uuid}/sync")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_sync_returns_404_for_unknown_uuid(): void
    {
        [, , , $user] = $this->seedEnvironment();

        Http::fake(F::fakeMap());

        $this->actingAs($user)
            ->postJson('/api/edo/documents/00000000-0000-0000-0000-000000000000/sync')
            ->assertNotFound();
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: Organization, 3: User}
     */
    private function seedEnvironment(): array
    {
        $tenant = Tenant::factory()->create();

        $org = Organization::factory()->create([
            'tenant_id'         => $tenant->id,
            'kind'              => 'manager',
            'edo_provider'      => EdoProvider::SberKorus->value,
            'edo_client_id'     => 'client-1',
            'edo_edi_code'      => '2BM-1',
            'edo_registered_at' => now(),
        ]);

        $counterparty = Organization::factory()->create([
            'tenant_id'         => $tenant->id,
            'kind'              => 'customer',
            'edo_provider'      => EdoProvider::SberKorus->value,
            'edo_client_id'     => 'client-2',
            'edo_edi_code'      => '2BM-2',
            'edo_registered_at' => now(),
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'role'            => 'manager',
        ]);

        return [$tenant, $org, $counterparty, $user];
    }
}
