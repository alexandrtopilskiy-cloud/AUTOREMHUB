<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для AttachmentController.
 *
 * Покрывает HTTP-контракт:
 *   - POST /api/requests/{id}/attachments      → 201 {request:{...}}
 *   - GET  /api/attachments/{attachmentId}/url → 200 {url, expiresIn}
 *   - GET  /api/attachments/{attachmentId}/download → 200 stream
 *
 * MediaService отдельно покрыт в MediaServiceTest — здесь только
 * маппинг на HTTP + цепочка проверок store().
 */
final class AttachmentControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGE_DRAFT          = 0;
    private const STAGE_VEHICLE_ACCEPT = 4;
    private const STAGE_CLOSING_DOCS   = 10;
    private const STAGE_AWAITING_PAY   = 11;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private User $customer;
    private User $manager;
    private User $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
        $this->managerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'manager',
            'name'      => 'УК',
        ]);

        $this->customer      = $this->makeUser($this->customerOrg, 'customer');
        $this->otherCustomer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager       = $this->makeUser($this->managerOrg, 'manager');
    }

    /* ─────────────────── store: happy ─────────────────── */

    public function test_store_creates_attachment_and_returns_201(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png', 100, 100),
            ],
        )->assertCreated();

        $this->assertSame(1, Attachment::query()->count());

        $attachment = Attachment::query()->firstOrFail();
        $this->assertSame(AttachmentPurpose::Evidence, $attachment->purpose);
        $this->assertSame(AttachmentMime::Png, $attachment->mime_type);
        $this->assertSame('media', $attachment->disk);
    }

    public function test_store_returns_request_document_with_attachment(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $response = $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertCreated();

        $attachment = Attachment::query()->firstOrFail();
        $response->assertJsonPath('request.id', $request->uuid);
        $this->assertContains(
            $attachment->uuid,
            collect($response->json('request.attachments'))->pluck('id')->all(),
        );
    }

    public function test_store_creates_request_event(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertCreated();

        $this->assertDatabaseHas('request_events', [
            'repair_request_id' => $request->id,
            'action'            => 'attachment',
        ]);
    }

    public function test_store_bumps_revision(): void
    {
        $request = $this->draftRequest($this->customer);
        $before = $request->revision;

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertCreated();

        $this->assertSame($before + 1, $request->refresh()->revision);
    }

    /* ─────────────────── store: валидация ─────────────────── */

    public function test_store_rejects_missing_file(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_empty_purpose(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => '',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_invalid_purpose(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'unknown_purpose',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_non_integer_revision(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => 'abc',
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_unsupported_mime(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->create('doc.txt', 10, 'text/plain'),
            ],
        )->assertUnprocessable();
    }

    /* ─────────────────── store: доступ и stage ─────────────────── */

    public function test_store_forbidden_for_manager_with_wrong_purpose(): void
    {
        $request = $this->requestAtStage($this->customer, self::STAGE_CLOSING_DOCS);

        $this->actingAs($this->manager);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertForbidden();
    }

    public function test_store_rejects_purpose_mismatch_with_stage(): void
    {
        // issued_invoice допустим только на stage=10, а мы на stage=0.
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'issued_invoice',
                'file'     => UploadedFile::fake()->image('invoice.png'),
            ],
        )->assertUnprocessable();
    }

    public function test_store_returns_404_for_unknown_request(): void
    {
        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . Str::uuid() . '/attachments',
            [
                'revision' => 1,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertNotFound();
    }

    public function test_store_returns_404_for_foreign_request(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->otherCustomer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertNotFound();
    }

    public function test_store_returns_409_for_revision_conflict(): void
    {
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision + 99,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertStatus(409);
    }

    /* ─────────────────── url ─────────────────── */

    public function test_url_returns_route_for_private_disk(): void
    {
        $request = $this->draftRequest($this->customer);
        $attachment = $this->makeAttachment($request, $this->customer, 'evidence');

        $this->actingAs($this->customer);
        $response = $this->getJson('/api/attachments/' . $attachment->uuid . '/url')
            ->assertOk()
            ->assertJsonPath('expiresIn', 300);

        $this->assertStringContainsString(
            '/api/attachments/' . $attachment->uuid . '/download',
            $response->json('url'),
        );
    }

    public function test_url_returns_404_for_unknown_attachment(): void
    {
        $this->actingAs($this->customer);
        $this->getJson('/api/attachments/' . Str::uuid() . '/url')
            ->assertNotFound();
    }

    public function test_url_returns_404_for_foreign_attachment(): void
    {
        $request = $this->draftRequest($this->customer);
        $attachment = $this->makeAttachment($request, $this->customer, 'evidence');

        $this->actingAs($this->otherCustomer);
        $this->getJson('/api/attachments/' . $attachment->uuid . '/url')
            ->assertNotFound();
    }

    /* ─────────────────── download ─────────────────── */

    public function test_download_returns_file_content(): void
    {
        $request = $this->draftRequest($this->customer);
        $attachment = $this->makeAttachment($request, $this->customer, 'evidence');

        Storage::disk('media')->put($attachment->object_key, 'file payload');

        $this->actingAs($this->customer);
        $response = $this->get('/api/attachments/' . $attachment->uuid . '/download')
            ->assertOk();

        $this->assertSame('file payload', $response->streamedContent());
    }

    public function test_download_returns_404_for_unknown_attachment(): void
    {
        $this->actingAs($this->customer);
        $this->get('/api/attachments/' . Str::uuid() . '/download')
            ->assertNotFound();
    }

    public function test_download_returns_404_when_file_missing_in_storage(): void
    {
        $request = $this->draftRequest($this->customer);
        $attachment = $this->makeAttachment($request, $this->customer, 'evidence');

        // В Storage ничего нет → MediaService::download() бросит 404 NOT_FOUND.
        $this->actingAs($this->customer);
        $this->get('/api/attachments/' . $attachment->uuid . '/download')
            ->assertNotFound();
    }

    public function test_store_rejects_manager_uploading_non_invoice_purpose(): void
    {
        // Manager на stage=10 (STAGE_CLOSING_DOCS) — canUpload = true,
        // но assertPurposeAllowedForRole отклонит purpose != issued_invoice.
        $request = $this->requestAtStage($this->customer, self::STAGE_CLOSING_DOCS);

        $this->actingAs($this->manager);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'evidence',
                'file'     => UploadedFile::fake()->image('photo.png'),
            ],
        )->assertForbidden();
    }

    public function test_store_rejects_paid_invoice_on_wrong_stage(): void
    {
        // paid_invoice допустим только на stage=11, а мы на stage=0.
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'paid_invoice',
                'file'     => UploadedFile::fake()->image('invoice.png'),
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_diagnostic_on_wrong_stage(): void
    {
        // diagnostic допустим только на stage=STAGE_REPAIR_IN_PROGRESS.
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'diagnostic',
                'file'     => UploadedFile::fake()->image('diag.png'),
            ],
        )->assertUnprocessable();
    }

    public function test_store_rejects_acceptance_act_on_wrong_stage(): void
    {
        // acceptance_act допустим только на stage=STAGE_WAITING_VEHICLE.
        $request = $this->draftRequest($this->customer);

        $this->actingAs($this->customer);
        $this->postJson(
            '/api/requests/' . $request->uuid . '/attachments',
            [
                'revision' => $request->revision,
                'purpose'  => 'acceptance_act',
                'file'     => UploadedFile::fake()->image('act.png'),
            ],
        )->assertUnprocessable();
    }

    /* ─────────────────── helpers ─────────────────── */

    private function draftRequest(User $owner): RepairRequest
    {
        return $this->requestAtStage($owner, self::STAGE_DRAFT);
    }

    private function requestAtStage(User $owner, int $stage): RepairRequest
    {
        $uuid = (string) Str::uuid();

        $request = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $owner->organization_id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => $stage,
            'revision'                 => 1,
            'created_by'               => $owner->id,
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'stage'             => $stage,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $owner->id,
        ]);

        return $request;
    }

    private function makeAttachment(
        RepairRequest $request,
        User $uploader,
        string $purpose,
    ): Attachment {
        $period = RequestStatusPeriod::query()
        ->where('repair_request_id', $request->id)
            ->whereNull('exited_at')
            ->firstOrFail();

        return Attachment::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'repair_request_id'        => $request->id,
            'uploaded_by'              => $uploader->id,
            'request_status_period_id' => $period->id,
            'purpose'                  => $purpose,
            'disk'                     => 'media',
            'object_key'               => 'test/' . Str::uuid() . '.png',
            'original_name'            => 'photo.png',
            'mime_type'                => 'image/png',
            'size'                     => 100,
            'sha256'                   => str_repeat('a', 64),
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $role . '-' . Str::random(10),
            'display_name'    => ucfirst($role),
            'role'            => $role,
            'password'        => 'test-password-long',
            'active'          => true,
        ]);
    }
}
