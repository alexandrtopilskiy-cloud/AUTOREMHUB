<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\DiagnosticReport;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestApproval;
use App\Models\RequestEvent;
use App\Models\RequestPart;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAcceptanceAct;
use App\Models\VehicleContact;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\RepairRequestService;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Characterization-тест для RepairRequestService::validateNext().
 *
 * Проверяет все ветки валидации переходов:
 *   - validateDraftStage (0 / 1)
 *   - назначение СТО (1)
 *   - validateManagerReviewStage (1) ← новое в dev
 *   - validateEstimateStage / validatePartsReceivedStage (5)
 *   - workOrder requirement (6, 7, 8)
 *   - invoice and payment checks (8, 9, 10)
 *
 * Пока в проекте нет enum'ов — работает с int-этапами.
 * После этапа 0 — переписать на RepairStage enum.
 */
final class RepairRequestValidateNextTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGE_DRAFT            = 0;
    private const STAGE_REVIEW           = 1;
    private const STAGE_ESTIMATE_REVIEW  = 5;
    private const STAGE_READY_TO_REPAIR  = 5;
    private const STAGE_READY_TO_ISSUE   = 6;
    private const STAGE_ISSUE            = 7;
    private const STAGE_CLOSING_DOCS     = 8;
    private const STAGE_AWAITING_PAYMENT = 9;
    private const STAGE_PAYMENT_REVIEW   = 10;

    private Tenant $tenant;
    private Organization $customerOrg;
    private User $customer;
    private User $manager;
    private Vehicle $vehicle;
    private VehicleContact $contact;
    private Station $station;
    private Supplier $supplier;
    private ServiceReason $reason;
    private WorkType $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
        $managerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'manager',
            'name'      => 'УК',
        ]);
        $stationOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'station',
            'name'      => 'СТО',
        ]);

        $this->customer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager  = $this->makeUser($managerOrg, 'manager');

        $this->vehicle = Vehicle::query()->create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->customerOrg->id,
            'name'             => 'Lada Vesta',
            'vin'              => 'XTA00000000000001',
            'plate'            => 'А001АА77',
            'plate_normalized' => 'А001АА77',
            'year'             => 2025,
            'labor_hour_rate'  => 1000,
        ]);

        $this->contact = VehicleContact::query()->create([
            'uuid'        => (string) Str::uuid(),
            'vehicle_id'  => $this->vehicle->id,
            'kind'        => 'contact',
            'last_name'   => 'Иванов',
            'first_name'  => 'Иван',
            'phone'       => '+79990000000',
        ]);

        $this->station = Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $stationOrg->id,
            'name'            => 'Тестовая СТО',
            'address'         => 'Москва',
        ]);

        $this->supplier = Supplier::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Поставщик',
        ]);

        $this->reason = ServiceReason::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Диагностика',
            'code'      => 'diagnostics',
            'active'    => true,
        ]);

        $this->work = WorkType::query()->create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Диагностика',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);
    }

    /**
     * @return array<string, array{int, array<int, string>, string|null}>
     */
    public static function validateNextProvider(): array
    {
        return [
            /* ─── Draft (0) ─── */
            'Draft: пустая' => [
                self::STAGE_DRAFT,
                [],
                'Выберите автомобиль из перечня вашей организации',
            ],
            'Draft: без reason' => [
                self::STAGE_DRAFT,
                ['vehicle'],
                'Выберите причину обращения',
            ],
            'Draft: без mileage' => [
                self::STAGE_DRAFT,
                ['vehicle', 'reason'],
                'Укажите текущий пробег',
            ],
            'Draft: без contact' => [
                self::STAGE_DRAFT,
                ['vehicle', 'reason', 'mileage'],
                'Выберите контактное лицо',
            ],
            'Draft: ок' => [
                self::STAGE_DRAFT,
                ['vehicle', 'reason', 'mileage', 'contact'],
                null,
            ],

            /* ─── Review (1) ─── */
            'Review: без slot' => [
                self::STAGE_REVIEW,
                ['vehicle', 'reason', 'mileage', 'contact'],
                'Укажите желаемое время визита',
            ],
            'Review: без station' => [
                self::STAGE_REVIEW,
                ['vehicle', 'reason', 'mileage', 'contact', 'slot'],
                'Назначьте СТО',
            ],
            'Review: без работ (validateManagerReviewStage)' => [
                self::STAGE_REVIEW,
                ['vehicle', 'reason', 'mileage', 'contact', 'slot', 'station'],
                'Добавьте хотя бы одну работу',
            ],
            'Review: пустое имя работы' => [
                self::STAGE_REVIEW,
                ['vehicle', 'reason', 'mileage', 'contact', 'slot', 'station', 'work_empty_name'],
                'Заполните название и положительное количество работы',
            ],
            'Review: ок' => [
                self::STAGE_REVIEW,
                ['vehicle', 'reason', 'mileage', 'contact', 'slot', 'station', 'work_valid'],
                null,
            ],

            /* ─── EstimateReview (5) ─── */
            'EstimateReview: без diagnoses' => [
                self::STAGE_ESTIMATE_REVIEW,
                ['acceptance'],
                'СТО должна сохранить заключение диагностики',
            ],
            'EstimateReview: без planned' => [
                self::STAGE_ESTIMATE_REVIEW,
                ['acceptance', 'diagnosis'],
                'Укажите плановую дату и время завершения',
            ],
            'EstimateReview: без works' => [
                self::STAGE_ESTIMATE_REVIEW,
                ['acceptance', 'diagnosis', 'planned'],
                'Заполните перечень работ',
            ],
            'EstimateReview: пустое имя работы' => [
                self::STAGE_ESTIMATE_REVIEW,
                ['acceptance', 'diagnosis', 'planned', 'work_empty_name'],
                'Заполните названия и положительное количество работ',
            ],
            'EstimateReview: part без supplier' => [
                self::STAGE_ESTIMATE_REVIEW,
                ['acceptance', 'diagnosis', 'planned', 'work_valid', 'part_no_supplier'],
                'Укажите поставщика, название и количество каждой запчасти',
            ],
            'EstimateReview: ок' => [
                self::STAGE_ESTIMATE_REVIEW,
                ['acceptance', 'diagnosis', 'planned', 'work_valid', 'part_ok', 'approval_stage3_ok'],
                null,
            ],

            /* ─── ReadyToRepair (7) с approvals ─── */
            'ReadyToRepair: approvals stage6 с неверной версией' => [
                self::STAGE_READY_TO_REPAIR,
                ['acceptance', 'diagnosis', 'planned', 'work_valid', 'approval_stage3_wrong_version'],
                'Текущая смета должна быть согласована Заказчиком',
            ],
            'ReadyToRepair: part не получен' => [
                self::STAGE_READY_TO_REPAIR,
                ['acceptance', 'diagnosis', 'planned', 'work_valid', 'approval_stage3_ok', 'part_proposed'],
                'Подтвердите получение согласованных запчастей',
            ],
            'ReadyToRepair: ок' => [
                self::STAGE_READY_TO_REPAIR,
                ['acceptance', 'diagnosis', 'planned', 'work_valid', 'approval_stage3_ok', 'part_received_ok'],
                null,
            ],

            /* ─── ReadyToIssue / Issue / ClosingDocs (8, 9, 10) ─── */
            'ReadyToIssue: без workOrder' => [
                self::STAGE_READY_TO_ISSUE,
                [],
                'Заказ-наряд ещё не сформирован',
            ],
            'Issue: без workOrder' => [
                self::STAGE_ISSUE,
                [],
                'Заказ-наряд ещё не сформирован',
            ],
            'ClosingDocs: без workOrder' => [
                self::STAGE_CLOSING_DOCS,
                [],
                'Заказ-наряд ещё не сформирован',
            ],
            'ClosingDocs: без invoice' => [
                self::STAGE_CLOSING_DOCS,
                ['work_order'],
                'Прикрепите счёт на полную сумму заказ-наряда',
            ],
            'ClosingDocs: invoice не совпадает по сумме' => [
                self::STAGE_CLOSING_DOCS,
                ['work_order', 'invoice_wrong_total'],
                'Прикрепите счёт на полную сумму заказ-наряда',
            ],
            'ClosingDocs: ок' => [
                self::STAGE_CLOSING_DOCS,
                ['work_order', 'invoice_issued'],
                null,
            ],

            /* ─── AwaitingPayment (11) ─── */
            'AwaitingPayment: без submitted invoice' => [
                self::STAGE_AWAITING_PAYMENT,
                ['work_order', 'invoice_issued'],
                'Прикрепите закрытый счёт и сведения об оплате',
            ],
            'AwaitingPayment: ок' => [
                self::STAGE_AWAITING_PAYMENT,
                ['work_order', 'invoice_submitted'],
                null,
            ],

            /* ─── PaymentReview (12) ─── */
            'PaymentReview: без paid' => [
                self::STAGE_PAYMENT_REVIEW,
                ['work_order', 'invoice_submitted'],
                'УК должна проверить оплату и закрытый счёт',
            ],
            'PaymentReview: paid без документа' => [
                self::STAGE_PAYMENT_REVIEW,
                ['work_order', 'invoice_paid_no_doc'],
                'УК должна проверить оплату и закрытый счёт',
            ],
            'PaymentReview: ок' => [
                self::STAGE_PAYMENT_REVIEW,
                ['work_order', 'invoice_paid_ok'],
                null,
            ],
        ];
    }

    #[DataProvider('validateNextProvider')]
    public function test_validate_next(int $stage, array $setup, ?string $expectedError): void
    {
        $request = $this->requestAt($stage);
        $this->applySetup($request, $setup);

        if ($expectedError !== null) {
            $this->expectException(ApiException::class);
            $this->expectExceptionMessage($expectedError);
        }

        $this->invokeValidateNext($request);

        if ($expectedError === null) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_saved_contact_snapshot_remains_valid_after_directory_deactivation(): void
    {
        $request = $this->requestAt(self::STAGE_REVIEW);
        $this->applySetup($request, [
            'vehicle',
            'reason',
            'mileage',
            'contact',
            'slot',
            'station',
            'work_valid',
        ]);
        $request->contact_snapshot = $this->contact->snapshot();
        $request->save();
        $this->contact->update(['active' => false]);

        $this->invokeValidateNext($request->refresh());

        $this->addToAssertionCount(1);
    }

    /* ─────────────────── setup ─────────────────── */

    private function applySetup(RepairRequest $request, array $steps): void
    {
        foreach ($steps as $step) {
            match ($step) {
                'vehicle'          => $request->vehicle_id = $this->vehicle->id,
                'reason'           => $request->reason_id = $this->reason->id,
                'mileage'          => $request->data = array_replace($request->data, ['mileage' => '15000']),
                'contact'          => $request->contact_id = $this->contact->id,
                'slot'             => $request->data = array_replace($request->data, ['slot' => '2026-10-20T11:00']),
                'station'          => $request->assigned_station_id = $this->station->id,
                'planned'          => $request->planned_completion_at = now()->addDay(),
                'diagnosis'        => $this->createDiagnosis($request),
                'acceptance'       => $this->createAcceptanceAct($request),
                'work_valid'       => $this->createWork($request, ['name' => 'Работа', 'quantity' => 1, 'price' => 100]),
                'work_empty_name'  => $this->createWork($request, ['name' => '', 'quantity' => 1, 'price' => 100]),
                'part_no_supplier' => $this->createPart($request, ['supplier_id' => null]),
                'part_ok'          => $this->createPart($request, [
                    'supplier_id' => $this->supplier->id,
                    'status'      => 'received',
                ]),
                'work_order'       => $this->createWorkOrder($request),
                'invoice_issued'   => $this->createInvoice($request, ['status' => 'issued']),
                'invoice_wrong_total' => $this->createInvoice($request, ['status' => 'issued', 'amount' => 999]),
                'invoice_submitted'   => $this->createInvoice($request, ['status' => 'submitted']),
                'invoice_paid_ok'     => $this->createInvoice($request, [
                    'status'                      => 'paid',
                    'paid_amount'                 => 100,
                    'paid_document_attachment_id' => $this->makeAttachment($request)->id,
                ]),
                'invoice_paid_no_doc' => $this->createInvoice($request, [
                    'status'      => 'paid',
                    'paid_amount' => 100,
                ]),
                'approval_stage3_ok'            => $this->createApproval($request, 3, 'accepted'),
                'approval_stage3_wrong_version' => $this->createApproval($request, 3, 'accepted', 999),
                'part_received_ok'              => $this->createPart($request, [
                    'supplier_id' => $this->supplier->id,
                    'status'      => 'received',
                ]),
                'part_proposed'                 => $this->createPart($request, [
                    'supplier_id'     => $this->supplier->id,
                    'status'           => 'proposed',
                    'approved_version' => null,
                ]),
                default => throw new \RuntimeException("Unknown setup step: {$step}"),
            };
        }

        $request->save();
        $request->refresh();
    }

    private function requestAt(int $stage): RepairRequest
    {
        $uuid = (string) Str::uuid();

        $request = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => $stage,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
            'estimate_version'         => 1,
        ]);

        RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'stage'             => $stage,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->customer->id,
        ]);

        return $request;
    }

    /* ─────────────────── хелперы создания ─────────────────── */

    private function createAcceptanceAct(RepairRequest $request): VehicleAcceptanceAct
    {
        $request->vehicle_id = $this->vehicle->id;
        $request->assigned_station_id = $this->station->id;
        $request->save();

        return VehicleAcceptanceAct::query()->create([
            'repair_request_id' => $request->id,
            'station_id'        => $this->station->id,
            'created_by'        => $this->manager->id,
            'number'            => 'AP-' . $request->number,
            'mode'              => 'electronic',
            'vehicle_snapshot'  => ['vin' => $this->vehicle->vin],
            'mileage'           => 15000,
            'fuel_level'        => 'half',
            'condition'         => 'Исправно',
            'defect_types'      => [],
            'diagram_marks'     => [],
            'locked_at'         => now(),
        ]);
    }

    private function createDiagnosis(RepairRequest $request): DiagnosticReport
    {
        return DiagnosticReport::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'station_id'        => $this->station->id,
            'author_id'         => $this->manager->id,
            'mileage'           => 15000,
            'conclusion'        => 'Заключение',
            'attachment_ids'    => [],
        ]);
    }

    private function createWork(RepairRequest $request, array $overrides = []): RequestWork
    {
        return RequestWork::query()->create(array_merge([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'work_type_id'      => $this->work->id,
            'name'              => 'Работа',
            'quantity'          => 1,
            'price'             => 100,
            'norm_hours'        => 1,
            'hourly_rate'       => 100,
            'line_amount'       => 100,
            'is_maintenance'    => false,
        ], $overrides));
    }

    private function createPart(RepairRequest $request, array $overrides = []): RequestPart
    {
        return RequestPart::query()->create(array_merge([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'name'              => 'Фильтр',
            'quantity'          => 1,
            'price'             => 100,
            'returned_quantity' => 0,
            'status'            => 'proposed',
            'unit'              => 'шт.',
            'approved_version'  => $request->estimate_version,
        ], $overrides));
    }

    private function createWorkOrder(RepairRequest $request): WorkOrder
    {
        return WorkOrder::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'vehicle_id'        => $this->vehicle->id,
            'station_id'        => $this->station->id,
            'mileage'           => 15000,
            'completed_at'      => now(),
            'total'             => 100,
            'conclusion'        => 'Готово',
            'estimate_version'  => $request->estimate_version,
        ]);
    }

    private function createApproval(
        RepairRequest $request,
        int $stage,
        string $decision,
        ?int $estimateVersion = null,
    ): RequestApproval {
        $event = RequestEvent::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'actor_id'          => $this->manager->id,
            'actor_label'       => 'Менеджер',
            'action'            => 'next',
            'comment'           => '',
            'stage'             => $stage,
            'revision'          => $request->revision,
            'occurred_at'       => now(),
        ]);

        return RequestApproval::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'request_event_id'  => $event->id,
            'actor_id'          => $this->manager->id,
            'stage'             => $stage,
            'decision'          => $decision,
            'comment'           => '',
            'estimate_version'  => $estimateVersion ?? $request->estimate_version,
            'snapshot'          => [],
        ]);
    }

    private function createInvoice(RepairRequest $request, array $overrides = []): Invoice
    {
        $document = $this->makeAttachment($request);

        return Invoice::query()->create(array_merge([
            'uuid'                    => (string) Str::uuid(),
            'repair_request_id'       => $request->id,
            'number'                  => 'СЧ-1',
            'amount'                  => 100,
            'status'                  => 'issued',
            'document_attachment_id'  => $document->id,
        ], $overrides));
    }

    private function makeAttachment(RepairRequest $request): Attachment
    {
        $period = RequestStatusPeriod::query()
        ->where('repair_request_id', $request->id)
            ->whereNull('exited_at')
            ->firstOrFail();

        return Attachment::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'repair_request_id'        => $request->id,
            'uploaded_by'              => $this->manager->id,
            'request_status_period_id' => $period->id,
            'purpose'                  => 'evidence',
            'disk'                     => 'media',
            'object_key'               => 'test/' . Str::uuid() . '.png',
            'original_name'            => 'photo.png',
            'mime_type'                => 'image/png',
            'size'                     => 100,
            'sha256'                   => str_repeat('a', 64),
        ]);
    }

    /* ─────────────────── invoke ─────────────────── */

    private function invokeValidateNext(RepairRequest $request): void
    {
        $method = new ReflectionMethod(RepairRequestService::class, 'validateNext');
        $method->setAccessible(true);
        $method->invoke($this->service(), $request);
    }

    private function service(): RepairRequestService
    {
        return app(RepairRequestService::class);
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
