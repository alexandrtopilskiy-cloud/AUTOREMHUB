<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Models\Attachment;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Edo\SberKorusResponseFactory as F;
use Tests\TestCase;

final class EdoDocumentControllerAcceptTitleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_accept_title_creates_attachment_and_updates_document(): void
    {
        [$tenant, $org, $counterparty, $user, $request] = $this->seedEnvironment();

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::ActWorks->value,
            'status'          => EdoDocumentStatus::Delivered,
            'external_id'     => '161355',
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'number'          => 'АКТ-1',
        ]);

        Http::fake([
            '*/api/auth/logon'              => Http::response(F::logon(), 200),
            '*/v2.0/document/*/acceptTitle' => Http::response(F::fileContent('act.xml', '<xml>act</xml>'), 200),
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/edo/documents/{$document->uuid}/accept-title",
            [
                'signer' => [
                    'inn'               => '7713026678',
                    'organization_name' => 'ООО «УК»',
                    'last_name'         => 'Иванов',
                    'first_name'        => 'Иван',
                    'middle_name'       => 'Иванович',
                    'title'             => 'Генеральный директор',
                    'authority'         => 'Устав',
                    'authority_scope'   => 0,
                    'condition'         => 1,
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'signed');
        $response->assertJsonPath('data.acceptedAt', fn ($v) => $v !== null);

        $document->refresh();

        $this->assertSame(EdoDocumentStatus::Signed, $document->status);
        $this->assertNotNull($document->accepted_at);
        $this->assertNotNull($document->acceptance_attachment_id);

        $attachment = Attachment::query()->findOrFail($document->acceptance_attachment_id);
        $this->assertSame(AttachmentPurpose::EdoPayload, $attachment->purpose);
        $this->assertSame(AttachmentMime::Xml, $attachment->mime_type);
        $this->assertSame('act.xml', $attachment->original_name);
    }

    public function test_accept_title_rejects_document_without_external_id(): void
    {
        [$tenant, $org, $counterparty, $user, $request] = $this->seedEnvironment();

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::ActWorks->value,
            'status'          => EdoDocumentStatus::Draft,
            'external_id'     => null,
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
        ]);

        Http::fake(F::fakeMap());

        $response = $this->actingAs($user)->postJson(
            "/api/edo/documents/{$document->uuid}/accept-title",
            [
                'signer' => [
                    'inn'               => '7713026678',
                    'organization_name' => 'ООО «УК»',
                    'last_name'         => 'Иванов',
                    'first_name'        => 'Иван',
                    'title'             => 'Генеральный директор',
                    'authority'         => 'Устав',
                    'authority_scope'   => 0,
                    'condition'         => 1,
                ],
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EDO_NO_EXTERNAL_ID');
    }

    public function test_accept_title_returns_404_for_other_tenant(): void
    {
        [$tenant, $org, $counterparty, $user, $request] = $this->seedEnvironment();

        $otherTenant = Tenant::factory()->create();
        $otherOrg = Organization::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::ActWorks->value,
            'status'          => EdoDocumentStatus::Delivered,
            'external_id'     => '161355',
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
        ]);

        Http::fake(F::fakeMap());

        $this->actingAs($otherUser)
            ->postJson("/api/edo/documents/{$document->uuid}/accept-title", [
            'signer' => [
                'inn'               => '7713026678',
                'organization_name' => 'ООО «УК»',
                'last_name'         => 'Иванов',
                'first_name'        => 'Иван',
                'title'             => 'Директор',
                'authority'         => 'Устав',
                'authority_scope'   => 0,
                'condition'         => 1,
            ],
        ])
            ->assertNotFound();
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: Organization, 3: User, 4: RepairRequest}
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

        $request = RepairRequest::factory()->create([
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $org->id,
            'created_by'               => $user->id,
        ]);

        RequestStatusPeriod::factory()->create([
            'repair_request_id' => $request->id,
            'actor_id'          => $user->id,
            'stage'             => 1,
            'entered_at'        => now()->subHour(),
            'exited_at'         => null,
        ]);

        return [$tenant, $org, $counterparty, $user, $request];
    }
}
