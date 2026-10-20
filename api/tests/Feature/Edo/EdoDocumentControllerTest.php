<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Edo\SberKorusResponseFactory as F;
use Tests\TestCase;

final class EdoDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_list(): void
    {
        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        EdoDocument::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $org->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft->value,
            'provider'        => 'sberkorus',
            'number'          => 'УПД-1',
            'document_date'   => now()->format('Y-m-d'),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => [],
        ]);

        $response = $this->actingAs($user)->getJson('/api/edo/documents');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'uuid', 'documentType', 'status', 'number'],
            ],
            'meta' => ['total', 'perPage', 'currentPage', 'lastPage'],
        ]);
        $response->assertJsonCount(1, 'data');
    }

    public function test_show_returns_document(): void
    {
        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        $document = EdoDocument::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $org->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft->value,
            'provider'        => 'sberkorus',
            'number'          => 'УПД-42',
            'document_date'   => now()->format('Y-m-d'),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => [],
        ]);

        $response = $this->actingAs($user)->getJson('/api/edo/documents/' . $document->uuid);

        $response->assertOk();
        $response->assertJsonPath('data.number', 'УПД-42');
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        // Другой тенант и пользователь
        $otherTenant = Tenant::query()->create(['name' => 'Other']);
        $otherOrg = Organization::query()->create([
            'tenant_id' => $otherTenant->id, 'kind' => 'manager',
            'name' => 'Other', 'short_name' => 'Other',
            'inn' => '9999999999', 'kpp' => '999999999', 'ogrn' => '9999999999999',
            'contact' => 'X', 'legal_address' => 'X', 'actual_address' => 'X', 'postal_address' => 'X',
            'director_name' => 'X', 'phone' => '+7', 'email' => 'x@x',
            'bank_name' => 'X', 'bik' => '000000000',
            'settlement_account' => '00000000000000000001',
            'correspondent_account' => '00000000000000000002',
            'tax_regime' => 'osn', 'vat_rate' => 20.00,
        ]);
        $otherUser = User::query()->create([
            'organization_id' => $otherOrg->id,
            'login' => 'other_' . uniqid(),
            'display_name' => 'Other',
            'role' => 'manager',
            'password' => bcrypt('x'),
            'active' => true,
        ]);

        $document = EdoDocument::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $org->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft->value,
            'provider'        => 'sberkorus',
            'number'          => 'УПД-1',
            'document_date'   => now()->format('Y-m-d'),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => [],
        ]);

        $response = $this->actingAs($otherUser)->getJson('/api/edo/documents/' . $document->uuid);

        $response->assertNotFound();
    }

    public function test_sign_and_send_returns_updated_document(): void
    {
        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        Http::fake([
            '*/api/auth/logon'      => Http::response(F::logon(), 200),
            '*/api/document/sign/*' => Http::response(null, 200),
            '*/api/document/send/*' => Http::response(null, 200),
        ]);

        $document = EdoDocument::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $org->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft->value,
            'provider'        => 'sberkorus',
            'external_id'     => '161355',
            'number'          => 'УПД-1',
            'document_date'   => now()->format('Y-m-d'),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => [],
        ]);

        $response = $this->actingAs($user)->postJson(
            '/api/edo/documents/' . $document->uuid . '/sign-and-send',
            ['signature_base64' => str_repeat('A', 200)],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'sended');
        $response->assertJsonPath('data.sentAt', fn ($v) => $v !== null);
    }

    public function test_sign_and_send_returns_422_for_non_draft(): void
    {
        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        $document = EdoDocument::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $org->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended->value,
            'provider'        => 'sberkorus',
            'external_id'     => '161355',
            'number'          => 'УПД-1',
            'document_date'   => now()->format('Y-m-d'),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => [],
        ]);

        $response = $this->actingAs($user)->postJson(
            '/api/edo/documents/' . $document->uuid . '/sign-and-send',
            ['signature_base64' => str_repeat('A', 200)],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EDO_NOT_DRAFT');
    }

    public function test_sign_and_send_validates_empty_signature(): void
    {
        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        $document = EdoDocument::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $org->id,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Draft->value,
            'provider'        => 'sberkorus',
            'external_id'     => '161355',
            'number'          => 'УПД-1',
            'document_date'   => now()->format('Y-m-d'),
            'source_type'     => RepairRequest::class,
            'source_id'       => $request->id,
            'meta'            => [],
        ]);

        $response = $this->actingAs($user)->postJson(
            '/api/edo/documents/' . $document->uuid . '/sign-and-send',
            ['signature_base64' => ''],
        );

        $response->assertStatus(422);
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: User, 3: RepairRequest}
     */
    private function seedEnvironment(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Test Tenant']);

        $org = Organization::query()->create([
            'tenant_id' => $tenant->id, 'kind' => 'manager',
            'name' => 'Test', 'short_name' => 'Test',
            'inn' => '7713026678', 'kpp' => '771301001', 'ogrn' => '1157746123456',
            'contact' => 'Test', 'legal_address' => 'Moscow',
            'actual_address' => 'Moscow', 'postal_address' => 'Moscow',
            'director_name' => 'Ivanov', 'phone' => '+7', 'email' => 't@e.com',
            'bank_name' => 'T', 'bik' => '044525225',
            'settlement_account' => '40817810000000000001',
            'correspondent_account' => '30101810400000000225',
            'tax_regime' => 'osn', 'vat_rate' => 20.00,
        ]);

        $user = User::query()->create([
            'organization_id' => $org->id,
            'login' => 'user_' . uniqid(),
            'display_name' => 'Test',
            'role' => 'manager',
            'password' => bcrypt('x'),
            'active' => true,
        ]);

        $request = RepairRequest::query()->create([
            'public_id'                => (string) Str::uuid(),
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $org->id,
            'revision'                 => 0,
            'stage'                    => 0,
            'data'                     => [],
            'works'                    => [],
            'history'                  => [],
            'estimate_version'         => 0,
            'review_context'           => 'initial',
            'review_baseline_version'  => 0,
            'created_by'               => $user->id,
        ]);

        return [$tenant, $org, $user, $request];
    }
}
