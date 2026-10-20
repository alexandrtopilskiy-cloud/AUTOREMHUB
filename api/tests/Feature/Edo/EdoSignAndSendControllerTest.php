<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EdoSignAndSendControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $org;
    private Organization $counterparty;
    private User $user;

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

        $this->org = Organization::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'kind'          => 'station',
            'inn'           => '7701234567',
            'edo_client_id' => '12345',
            'edo_edi_code'  => '2BK-7701234567-12345',
        ]);

        $this->counterparty = Organization::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'kind'          => 'customer',
            'inn'           => '7709876543',
            'edo_client_id' => '54321',
            'edo_edi_code'  => '2BK-7709876543-54321',
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'role'            => 'station',
        ]);
    }

    public function test_signs_and_sends_document(): void
    {
        $document = $this->makeDraftDocument();

        Http::fake([
            'courier.example.test/api/auth/logon'      => Http::response(['Token' => str_repeat('A', 100)], 200),
            'courier.example.test/api/document/sign/*' => Http::response([], 200),
            'courier.example.test/api/document/send/*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/edo/documents/{$document->uuid}/sign-and-send", [
                'signature_base64' => $this->fakeCms(),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', EdoDocumentStatus::Sended->value);

        $document->refresh();

        $this->assertSame(EdoDocumentStatus::Sended, $document->status);
        $this->assertNotNull($document->sent_at);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/document/sign/'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/document/send/'));
    }

    public function test_rejects_non_draft(): void
    {
        $document = $this->makeDraftDocument(['status' => EdoDocumentStatus::Sended]);

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/edo/documents/{$document->uuid}/sign-and-send", [
                'signature_base64' => $this->fakeCms(),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_NOT_DRAFT');

        Http::assertNothingSent();
    }

    public function test_rejects_when_no_external_id(): void
    {
        $document = $this->makeDraftDocument(['external_id' => null]);

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/edo/documents/{$document->uuid}/sign-and-send", [
                'signature_base64' => $this->fakeCms(),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_NO_EXTERNAL_ID');

        Http::assertNothingSent();
    }

    public function test_rejects_missing_signature(): void
    {
        $document = $this->makeDraftDocument();

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/edo/documents/{$document->uuid}/sign-and-send", []);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
            'error' => [
                'code',
                'message',
                'fields' => ['signature_base64'],
            ],
        ]);

        Http::assertNothingSent();
    }

    public function test_rejects_short_signature(): void
    {
        $document = $this->makeDraftDocument();

        Http::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/edo/documents/{$document->uuid}/sign-and-send", [
                'signature_base64' => 'short',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
            'error' => [
                'code',
                'message',
                'fields' => ['signature_base64'],
            ],
        ]);

        Http::assertNothingSent();
    }

    public function test_404_for_other_tenant(): void
    {
        $document = $this->makeDraftDocument();

        $otherTenant = Tenant::factory()->create();
        $otherOrg = Organization::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'role'            => 'station',
        ]);

        Http::fake();

        $response = $this->actingAs($otherUser)
            ->postJson("/api/edo/documents/{$document->uuid}/sign-and-send", [
                'signature_base64' => $this->fakeCms(),
            ]);

        $response->assertStatus(404);

        Http::assertNothingSent();
    }

    private function makeDraftDocument(array $overrides = []): EdoDocument
    {
        return EdoDocument::factory()->create(array_merge([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->org->id,
            'counterparty_id' => $this->counterparty->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft->value,
            'provider'        => EdoProviderEnum::SberKorus->value,
            'external_id'     => '999999',
            'number'          => 'УПД-1',
            'document_date'   => now()->toDateString(),
            'source_type'     => RepairRequest::class,
            'source_id'       => 1,
        ], $overrides));
    }

    /**
     * Base64 «валидной» подписи для тестов (≥100 символов).
     */
    private function fakeCms(): string
    {
        return base64_encode(random_bytes(200));
    }
}
