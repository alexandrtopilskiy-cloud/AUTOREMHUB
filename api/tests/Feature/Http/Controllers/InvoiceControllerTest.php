<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для InvoiceController.
 *
 * Разделение по роли × stage:
 *   - issue:   manager  на ClosingDocs (10)    — canIssueInvoice, attachment issued_invoice
 *   - payment: customer на AwaitingPayment (11) — canSubmitPayment, attachment paid_invoice
 *   - verify:  manager  на PaymentReview (12)  — canVerifyPayment
 */
final class InvoiceControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGE_CLOSING_DOCS     = 8;
    private const STAGE_AWAITING_PAYMENT = 9;
    private const STAGE_PAYMENT_REVIEW   = 10;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private Organization $stationOrg;
    private User $customer;
    private User $manager;
    private Station $stationRecord;

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
        $this->stationOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'station',
            'name'      => 'СТО',
        ]);

        $this->customer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager  = $this->makeUser($this->managerOrg, 'manager');

        $this->stationRecord = Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->stationOrg->id,
            'name'            => 'Тестовая СТО',
            'address'         => 'Москва',
        ]);
    }

    /* ─────────────────── issue (manager, stage=8) ─────────────────── */

    public function test_issue_creates_invoice_with_issued_status(): void
    {
        $request = $this->requestAtClosingDocs();
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/invoice', [
            'revision'     => $request['revision'],
            'number'       => 'СЧ-1',
            'amount'       => 100,
            'attachmentId' => $file->uuid,
        ])->assertOk();

        $invoice = Invoice::query()
        ->where('repair_request_id', $request['systemId'])
            ->firstOrFail();

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('100.00', $invoice->amount);
        $this->assertSame($file->id, $invoice->document_attachment_id);
        $this->assertNull($invoice->paid_at);
        $this->assertNull($invoice->paid_amount);
        $this->assertNull($invoice->verified_at);
    }

    public function test_issue_rejects_amount_mismatch_with_work_order(): void
    {
        $request = $this->requestAtClosingDocs();
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/invoice', [
            'revision'     => $request['revision'],
            'number'       => 'СЧ-1',
            'amount'       => 999,
            'attachmentId' => $file->uuid,
        ])->assertUnprocessable();
    }

    public function test_issue_rejects_missing_work_order(): void
    {
        $request = $this->requestAtClosingDocs(withWorkOrder: false);
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/invoice', [
            'revision'     => $request['revision'],
            'number'       => 'СЧ-1',
            'amount'       => 100,
            'attachmentId' => $file->uuid,
        ])->assertUnprocessable();
    }

    public function test_issue_rejects_empty_number(): void
    {
        $request = $this->requestAtClosingDocs();
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/invoice', [
            'revision'     => $request['revision'],
            'number'       => '   ',
            'amount'       => 100,
            'attachmentId' => $file->uuid,
        ])->assertUnprocessable();
    }

    public function test_issue_forbidden_for_customer(): void
    {
        $request = $this->requestAtClosingDocs();
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/invoice', [
            'revision'     => $request['revision'],
            'number'       => 'СЧ-1',
            'amount'       => 100,
            'attachmentId' => $file->uuid,
        ])->assertForbidden();
    }

    /* ─────────────────── payment (customer, stage=9) ─────────────────── */
    public function test_issue_links_attachment_to_event(): void
    {
        $request = $this->requestAtClosingDocs();
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/invoice', [
            'revision'     => $request['revision'],
            'number'       => 'СЧ-1',
            'amount'       => 100,
            'attachmentId' => $file->uuid,
        ])->assertOk();

        $this->assertNotNull($file->refresh()->request_event_id);
    }

    /* ─────────────────── payment (customer, stage=11) ─────────────────── */

    public function test_payment_marks_invoice_submitted(): void
    {
        $request = $this->requestAtAwaitingPayment();
        $file = $this->makeAttachment($request, 'paid_invoice');

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/payment', [
            'revision'     => $request['revision'],
            'paidAmount'   => 100,
            'paidAt'       => '2026-09-19T10:00',
            'attachmentId' => $file->uuid,
            'comment'      => 'Оплачено',
        ])->assertOk();

        $invoice = Invoice::query()
        ->where('repair_request_id', $request['systemId'])
            ->firstOrFail();

        $this->assertSame(InvoiceStatus::Submitted, $invoice->status);
        $this->assertSame('100.00', $invoice->paid_amount);
        $this->assertSame($file->id, $invoice->paid_document_attachment_id);
    }

    public function test_payment_rejects_amount_below_invoice(): void
    {
        $request = $this->requestAtAwaitingPayment();
        $file = $this->makeAttachment($request, 'paid_invoice');

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/payment', [
            'revision'     => $request['revision'],
            'paidAmount'   => 50,
            'paidAt'       => '2026-09-19T10:00',
            'attachmentId' => $file->uuid,
            'comment'      => 'Оплачено',
        ])->assertUnprocessable();
    }

    public function test_payment_rejects_future_paid_at(): void
    {
        $request = $this->requestAtAwaitingPayment();
        $file = $this->makeAttachment($request, 'paid_invoice');

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/payment', [
            'revision'     => $request['revision'],
            'paidAmount'   => 100,
            'paidAt'       => now()->addDay()->toISOString(),
            'attachmentId' => $file->uuid,
            'comment'      => 'Оплачено',
        ])->assertUnprocessable();
    }

    public function test_payment_rejects_empty_comment(): void
    {
        $request = $this->requestAtAwaitingPayment();
        $file = $this->makeAttachment($request, 'paid_invoice');

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/payment', [
            'revision'     => $request['revision'],
            'paidAmount'   => 100,
            'paidAt'       => '2026-09-19T10:00',
            'attachmentId' => $file->uuid,
            'comment'      => '',
        ])->assertUnprocessable();
    }

    public function test_payment_rejects_without_invoice(): void
    {
        $request = $this->requestAtAwaitingPayment(withInvoice: false);
        $file = $this->makeAttachment($request, 'paid_invoice');

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/payment', [
            'revision'     => $request['revision'],
            'paidAmount'   => 100,
            'paidAt'       => '2026-09-19T10:00',
            'attachmentId' => $file->uuid,
            'comment'      => 'Оплачено',
        ])->assertUnprocessable();
    }

    public function test_payment_rejects_attachment_with_wrong_purpose(): void
    {
        $request = $this->requestAtAwaitingPayment();
        $file = $this->makeAttachment($request, 'issued_invoice');

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/payment', [
            'revision'     => $request['revision'],
            'paidAmount'   => 100,
            'paidAt'       => '2026-09-19T10:00',
            'attachmentId' => $file->uuid,
            'comment'      => 'Оплачено',
        ])->assertUnprocessable();
    }

    public function test_payment_forbidden_for_manager(): void
    {
        $request = $this->requestAtAwaitingPayment();
        $file = $this->makeAttachment($request, 'paid_invoice');

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/payment', [
            'revision'     => $request['revision'],
            'paidAmount'   => 100,
            'paidAt'       => '2026-09-19T10:00',
            'attachmentId' => $file->uuid,
            'comment'      => 'Оплачено',
        ])->assertForbidden();
    }

    /* ─────────────────── verify (manager, stage=10) ─────────────────── */

    public function test_verify_marks_invoice_paid(): void
    {
        $request = $this->requestAtPaymentReview();

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/payment/verify', [
            'revision' => $request['revision'],
            'comment'  => 'Проверено по выписке',
        ])->assertOk();

        $invoice = Invoice::query()
        ->where('repair_request_id', $request['systemId'])
            ->firstOrFail();

        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame($this->manager->id, $invoice->verified_by);
        $this->assertNotNull($invoice->verified_at);
    }

    public function test_verify_rejects_without_submitted_invoice(): void
    {
        $request = $this->requestAtPaymentReview(withSubmittedInvoice: false);

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/payment/verify', [
            'revision' => $request['revision'],
            'comment'  => 'Проверено',
        ])->assertUnprocessable();
    }

    public function test_verify_rejects_incomplete_paid_amount(): void
    {
        $request = $this->requestAtPaymentReview(paidAmount: '50.00');

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/payment/verify', [
            'revision' => $request['revision'],
            'comment'  => 'Проверено',
        ])->assertUnprocessable();
    }

    public function test_verify_rejects_empty_comment(): void
    {
        $request = $this->requestAtPaymentReview();

        $this->actingAs($this->manager);
        $this->postJson('/api/requests/' . $request['id'] . '/payment/verify', [
            'revision' => $request['revision'],
            'comment'  => '',
        ])->assertUnprocessable();
    }

    public function test_verify_forbidden_for_customer(): void
    {
        $request = $this->requestAtPaymentReview();

        $this->actingAs($this->customer);
        $this->postJson('/api/requests/' . $request['id'] . '/payment/verify', [
            'revision' => $request['revision'],
            'comment'  => 'Проверено',
        ])->assertForbidden();
    }

    /* ─────────────────── Общие ─────────────────── */

    public function test_unknown_request_returns_404(): void
    {
        $this->actingAs($this->manager);

        $this->postJson('/api/requests/' . Str::uuid() . '/invoice', [
            'revision'     => 1,
            'number'       => 'СЧ-1',
            'amount'       => 100,
            'attachmentId' => Str::uuid(),
        ])->assertNotFound();
    }

    /* ─────────────────── helpers ─────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function requestAtClosingDocs(bool $withWorkOrder = true): array
    {
        return $this->makeRequest(self::STAGE_CLOSING_DOCS, $withWorkOrder);
    }

    private function requestAtAwaitingPayment(bool $withInvoice = true): array
    {
        $request = $this->makeRequest(self::STAGE_AWAITING_PAYMENT, true);

        if ($withInvoice) {
            $file = $this->makeAttachment($request, 'issued_invoice');
            $this->makeInvoice($request, $file, 'issued', '100.00');
        }

        return $request;
    }

    private function requestAtPaymentReview(
        bool $withSubmittedInvoice = true,
        ?string $paidAmount = null,
    ): array {
        $request = $this->makeRequest(self::STAGE_PAYMENT_REVIEW, true);

        $file = $this->makeAttachment($request, 'issued_invoice');
        $paidFile = $this->makeAttachment($request, 'paid_invoice');

        $status = $withSubmittedInvoice ? 'submitted' : 'issued';

        $this->makeInvoice(
            $request,
            $file,
            $status,
            '100.00',
            paidDocumentId: $withSubmittedInvoice ? $paidFile->id : null,
            paidAmount: $withSubmittedInvoice
                ? ($paidAmount ?? '100.00')
                : null,
            paidAt: $withSubmittedInvoice ? now()->subDay() : null,
        );

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRequest(int $stage, bool $withWorkOrder): array
    {
        $uuid = (string) Str::uuid();

        $model = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'assigned_station_id'      => $this->stationRecord->id,
            'data'                     => array_replace(
                array_fill_keys(Workflow::TEXT_FIELDS, ''),
                ['mileage' => '15000', 'slot' => '2026-10-20T11:00'],
            ),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => $stage,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
            'estimate_version'         => 1,
            'planned_completion_at'    => now()->addDay(),
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $model->id,
            'stage'             => $stage,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->customer->id,
        ]);

        RequestWork::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $model->id,
            'name'              => 'Работа',
            'quantity'          => 1,
            'price'             => 100,
            'is_maintenance'    => false,
        ]);

        if ($withWorkOrder) {
            WorkOrder::query()->create([
                'uuid'              => (string) Str::uuid(),
                'repair_request_id' => $model->id,
                'vehicle_id'        => $this->makeVehicle()->id,
                'station_id'        => $this->stationRecord->id,
                'mileage'           => 15000,
                'completed_at'      => now(),
                'total'             => 100,
                'conclusion'        => 'ok',
                'estimate_version'  => 1,
            ]);
        }

        return [
            'id'              => $model->uuid,
            'systemId'        => $model->id,
            'revision'        => 1,
            'estimateVersion' => 1,
            'stage'           => $stage,
        ];
    }

    private function makeVehicle(): Vehicle
    {
        $existing = Vehicle::query()
        ->where('organization_id', $this->customerOrg->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
        ]);
    }

    private function makeAttachment(
        array $request,
        string $purpose,
    ): Attachment {
        $period = RequestStatusPeriod::query()
        ->where('repair_request_id', $request['systemId'])
            ->whereNull('exited_at')
            ->firstOrFail();

        return Attachment::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'repair_request_id'        => $request['systemId'],
            'uploaded_by'              => $this->customer->id,
            'request_status_period_id' => $period->id,
            'purpose'                  => $purpose,
            'disk'                     => 'media',
            'object_key'               => 'test/' . Str::uuid() . '.pdf',
            'original_name'            => 'invoice.pdf',
            'mime_type'                => 'application/pdf',
            'size'                     => 100,
            'sha256'                   => str_repeat('a', 64),
        ]);
    }

    private function makeInvoice(
        array $request,
        Attachment $document,
        string $status,
        string $amount,
        ?int $paidDocumentId = null,
        ?string $paidAmount = null,
        ?\Carbon\CarbonInterface $paidAt = null,
    ): Invoice {
        return Invoice::query()->create([
            'uuid'                       => (string) Str::uuid(),
            'repair_request_id'          => $request['systemId'],
            'number'                     => 'СЧ-1',
            'amount'                     => $amount,
            'status'                     => $status,
            'document_attachment_id'     => $document->id,
            'paid_document_attachment_id' => $paidDocumentId,
            'paid_amount'                => $paidAmount,
            'paid_at'                    => $paidAt,
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
