<?php

declare(strict_types=1);

namespace App\Support;

use App\DTO\UserDto;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\PartMovement;
use App\Models\Recommendation;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Supplier;
use App\Models\TimeNorm;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\RepairRequestCalculator;
use App\Services\RepairRequestService;
use App\Services\Workflow;
use App\Services\WorkChangeService;
use App\Services\ClosingDocumentService;
use App\Services\StockService;
use App\Enums\AttachmentPurpose;

final class ApiPresenter
{
    private array $recommendationSummaries = [];

    public function __construct(
        private readonly RepairRequestCalculator $repairRequestCalculator,
    ) {}

    /* ─────────────────── Публичный API ─────────────────── */

    /** Загружает ссылки рекомендаций одним запросом для реестра заявок. */
    public function preloadRecommendations(iterable $requests, UserDto $dto): void
    {
        $vehicleIds = collect($requests)->pluck('vehicle_id')->filter()->unique()->all();
        $this->recommendationSummaries = array_fill_keys($vehicleIds, []);

        if ($vehicleIds === []) {
            return;
        }

        $docs = Recommendation::forUser($dto)
            ->whereIn('vehicle_id', $vehicleIds)
            ->withCount(['items as open_items_count' => fn ($query) => $query->whereNull('moved_at')->whereNull('deleted_at')])
            ->orderByDesc('id')
            ->get(['id', 'vehicle_id', 'uuid', 'number']);
        foreach ($docs as $doc) {
            $this->recommendationSummaries[$doc->vehicle_id][] = self::recommendationLink($doc);
        }
    }

    /** Ссылка на документ рекомендаций ТС и число ещё не перенесённых строк. */
    private static function recommendationLink(Recommendation $doc): array
    {
        return [
            'id' => $doc->uuid,
            'number' => $doc->number,
            'openItems' => (int) ($doc->open_items_count ?? 0),
        ];
    }

    public function recommendationSummary(RepairRequest $req, UserDto $dto): array
    {
        if ($req->vehicle_id === null) {
            return [];
        }

        // 1. Проверяем кэш (in-memory)
        if (isset($this->recommendationSummaries[$req->vehicle_id])) {
            return $this->recommendationSummaries[$req->vehicle_id];
        }

        // 2. Если в кэше нет — идем в базу
        $recommendations = Recommendation::forUser($dto)
            ->where('vehicle_id', $req->vehicle_id)
            ->withCount(['items as open_items_count' => fn ($query) => $query->whereNull('moved_at')->whereNull('deleted_at')])
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'number'])
            ->map(fn ($doc) => self::recommendationLink($doc))
            ->all();

        // 3. Сохраняем в кэш (если вы это делаете где-то еще, иначе смысла нет)
         $this->recommendationSummaries[$req->vehicle_id] = $recommendations;

        return $recommendations;
    }

    /**
     * @return array<string, mixed>
     */
    public function document(RepairRequest $req, UserDto $dto): array
    {
        $req->load([
            'vehicleRecord.organization',
            'vehicleRecord.modelReference',
            'contactRecord',
            'driverRecord',
            'stationRecord.organization',
            'reasonRecord',
            'reasonSubtype',
            'workLines',
            'parts.catalogPart',
            'events',
            'periods',
            'attachments',
            'diagnoses',
            'approvals',
            'workOrder.items',
            'invoice',
            'acceptanceAct.attachment',
            'documents.attachment',
            'documents.signedAttachment',
        ]);

        $data = $this->resolveData($req);
        $vehicle = $req->vehicleRecord;
        $contact = $req->contactRecord;
        $driver = $req->driverRecord;
        $station = $req->stationRecord;
        $timezone = (string) config('sto.service_timezone');

        return CommercialVisibility::forUser(array_merge($data, [
            'id'                     => $req->uuid,
            'uuid'                   => $req->uuid,
            'systemId'               => $req->id,
            'number'                 => $req->number,
            'revision'               => $req->revision,
            'stage'                  => $req->stage->value,
            'createdAt'              => $req->created_at->toISOString(),
            'updatedAt'              => $req->updated_at->toISOString(),
            'estimateVersion'        => $req->estimate_version,
            'reviewContext'          => $req->review_context,
            'declinedStationIds' => $request->data['declinedStationIds'] ?? [],
            'statusLabel' => Workflow::statusLabel($req),
            'ownerLabel' => Workflow::ownerLabel($req),
            'workChange' => app(WorkChangeService::class)->present($req, $dto),
            'reviewBaselineVersion'  => $req->review_baseline_version,
            'estimateChangedSinceReview' => app(RepairRequestService::class)->estimateChangedSinceReview($req),
            'vehicleId'              => $vehicle?->uuid ?? '',
            'vehicle'                => $vehicle?->name ?? '',
            'make'                   => $vehicle?->make ?? '',
            'model'                  => $vehicle?->model ?? '',
            'vehicleModelId'         => $vehicle?->modelReference?->uuid ?? '',
            'vin'                    => $vehicle?->vin ?? '',
            'plate'                  => $vehicle?->plate ?? '',
            'year'                   => $vehicle?->year,
            'company'                => $dto->role === UserRole::Station
                ? ''
                : ($vehicle?->organization?->name ?? ''),
            'previousServiceMileage' => $req->previous_service_mileage,
            'previousServiceAt'      => $req->previous_service_at?->toISOString(),
            'laborRateType' => ($data['laborRateType'] ?? '') ?: 'general',
            'laborRates' => $dto->role === UserRole::Manager ? [
                'general' => $vehicle?->laborRate('general'),
                'official' => $vehicle?->laborRate('official'),
                'other' => $vehicle?->laborRate('other'),
            ] : [],
            'laborHourRate'          => $dto->role !== UserRole::Customer
                ? ($vehicle?->laborRate($data['laborRateType'] ?? 'general') !== null
                    ? (float) $vehicle->laborRate($data['laborRateType'] ?? 'general') : null)
                : null,
            'contactId'              => $contact?->uuid ?? '',
            'contact'                => $this->formatContact($req, $data),
            'contactDetails'         => $request->contact_snapshot ?? $contact?->snapshot(),
            'driverId'               => $driver?->uuid ?? '',
            'driver'                 => $this->formatDriver($req),
            'driverDetails'          => $request->driver_snapshot ?? $driver?->snapshot(),
            'stationId'              => $station?->uuid ?? '',
            'station'                => $this->formatStation($station),
            'stationDetails'         => $station?->referenceDetails(),
            'reasonId'               => $request->reasonRecord?->uuid ?? '',
            'reasonSubtypeId'        => $request->reasonSubtype?->uuid ?? '',
            'reason'                 => $request->reasonRecord?->name ?? '',
            'diagnostics'            => $request->reasonSubtype?->name ?? '',
            'plannedCompletionAt'    => $this->formatPlannedCompletionAt($req, $timezone),
            'repairCompletedAt'      => $req->repair_completed_at?->toISOString(),
            'overdueSeconds'         => $this->calculateOverdueSeconds($req),
            'statusPeriods'          => $this->presentStatusPeriods($req),
            'recommendations'        => $this->recommendationSummary($req, $dto),
            'works'                  => $this->presentWorks($req, $dto),
            'partMovements'          => $this->presentPartMovements($req),
            'parts'                  => $this->presentParts($req, $dto),
            'total'                  => (float) $this->repairRequestCalculator->total($req),
            ...(AgentDiscount::visibleTo($dto->role)
                ? ['agentDiscount' => AgentDiscount::summary($req)]
                : []),
            'history'                => $this->presentHistory($req, $timezone),
            'attachments'            => $this->presentAttachments($req),
            'currentPeriodId'        => $this->currentPeriodId($req),
            'diagnosticReports'      => $this->presentDiagnosticReports($req),
            'approvals'              => $this->presentApprovals($req),
            'workOrder'              => $this->workOrder($req->workOrder),
            'invoice'                => $this->presentInvoice($req),
            'acceptanceAct'          => $this->presentAcceptanceAct($req),
            'closing'                => app(ClosingDocumentService::class)->present($req, $dto),
            // Склад: нехватка ЗЧ у СТО (этапы 4/5) и «ЗЧ от УК» (галочка, Перемещение).
            ...app(StockService::class)->presentRequest($req, $dto->role),
            'permissions' => array_merge(Workflow::permissions($req, $dto), [
                'canViewRepairEstimate' => $dto->role !== UserRole::Customer || $req->stage->value >= RepairStage::CustomerApproval->value
                    || $req->periods->contains(fn ($period) => (int) $period->getRawOriginal('stage') >= RepairStage::CustomerApproval->value),
            ]),
        ]), $dto->role);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workOrder(?WorkOrder $order): ?array
    {
        if ($order === null) {
            return null;
        }

        return [
            'id'         => $order->uuid,
            'systemId'   => $order->id,
            'number'     => $order->number,
            'mileage'    => $order->mileage,
            'completedAt' => $order->completed_at->toISOString(),
            'total'      => (float) $order->total,
            'conclusion' => $order->conclusion,
            'items'      => $this->presentWorkOrderItems($order),
        ];
    }

    /* ─────────────────── Секции документа ─────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function resolveData(RepairRequest $req): array
    {
        return array_replace(
            array_fill_keys(Workflow::TEXT_FIELDS, ''),
            array_intersect_key($req->data, array_flip(Workflow::TEXT_FIELDS)),
        );
    }

    private function formatStation(?object $station): string
    {
        if ($station === null) {
            return '';
        }

        return implode(' · ', array_filter([$station->name, $station->address]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatContact(RepairRequest $req, array $data): string
    {
        $snapshot = $req->contact_snapshot;

        if (is_array($snapshot)) {
            return implode(' · ', array_filter([
                $snapshot['fullName'] ?? '',
                RussianPhone::format($snapshot['phone'] ?? ''),
            ]));
        }

        return (string) ($data['contact'] ?? '');
    }

    private function formatDriver(RepairRequest $req): string
    {
        $snapshot = $req->driver_snapshot;

        if (!is_array($snapshot)) {
            return '';
        }

        return implode(' · ', array_filter([
            $snapshot['fullName'] ?? '',
            RussianPhone::format($snapshot['phone'] ?? ''),
        ]));
    }

    private function formatPlannedCompletionAt(RepairRequest $req, string $timezone): string
    {
        if ($req->planned_completion_at === null) {
            return '';
        }

        return $req->planned_completion_at
            ->setTimezone($timezone)
            ->format('Y-m-d\TH:i');
    }

    private function calculateOverdueSeconds(RepairRequest $req): int
    {
        if (
            $req->planned_completion_at === null
            || $req->stage->value < RepairStage::RepairInProgress->value
        ) {
            return 0;
        }

        $end = $request->repair_completed_at ?? now();

        if (!$end->greaterThan($req->planned_completion_at)) {
            return 0;
        }

        return (int) $req->planned_completion_at->diffInSeconds($end);
    }

    /* ─────────────────── Секции: workLines, parts, movements ─────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentWorks(RepairRequest $req, UserDto $dto): array
    {
        $workTypeIds = $req->workLines->pluck('work_type_id')->filter()->unique()->all();
        $timeNormIds = $req->workLines->pluck('time_norm_id')->filter()->unique()->all();

        $workTypes = WorkType::query()
            ->whereIn('id', $workTypeIds)
            ->get()
            ->keyBy('id');
        $timeNorms = TimeNorm::query()
            ->with(TimeNormDescription::RELATIONS)
            ->whereIn('id', $timeNormIds)
            ->get()
            ->keyBy('id');

        return $req->workLines
            ->map(fn ($work) => array_merge([
                'id'          => $work->uuid,
                'name'        => $work->name,
                'type'        => 'Работа',
                'qty'         => (float) $work->quantity,
                'price'       => $work->price !== null ? (float) $work->price : null,
                'amount'      => $work->line_amount !== null
                    ? (float) $work->line_amount
                    : null,
                'lineAmount'  => $work->line_amount !== null
                    ? (float) $work->line_amount
                    : null,
                'hourlyRate'  => $work->hourly_rate !== null
                    ? (float) $work->hourly_rate
                    : null,
                'workCode'    => $work->work_code_snapshot,
                'workTypeId'  => $workTypes->get($work->work_type_id)?->uuid ?? '',
                'timeNormId'  => $timeNorms->get($work->time_norm_id)?->uuid ?? '',
                'normId'      => $work->norm_id_snapshot,
                'normSelectionScope' => $work->norm_selection_scope,
                'normApplicabilityLabel' => $work->norm_is_manual ? 'Ручная норма для этой заявки' : TimeNormDescription::label($timeNorms->get($work->time_norm_id)),
                'normIsManual' => (bool) $work->norm_is_manual,
                'normOverrideReason' => $work->norm_override_reason,
                'normHours'   => $work->norm_hours !== null ? (float) $work->norm_hours : null,
                'actualHours' => $work->actual_hours !== null ? (float) $work->actual_hours : null,
                'comment'     => (string) ($work->comment ?? ''),
                'changeNote'  => (string) ($work->change_note ?? ''),
            ], AgentDiscount::visibleTo($dto->role) ? AgentDiscount::workFields($work) : []))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentParts(RepairRequest $req, UserDto $dto): array
    {
        $supplierIds = $req->parts->pluck('supplier_id')->filter()->unique()->all();
        $suppliers = Supplier::query()->whereIn('id', $supplierIds)->get()->keyBy('id');

        return $req->parts
            ->map(function ($part) use ($suppliers, $dto): array {
                $supplier = $suppliers->get($part->supplier_id);

                return array_merge([
                    'id'              => $part->uuid,
                    'systemId'        => $part->id,
                    'name'            => $part->name,
                    'article'         => $part->article,
                    'manufacturer'    => $part->manufacturer_snapshot ?? $part->catalogPart?->manufacturer ?? '',
                    'fromManager' => (bool) $part->from_manager,
                    'purchasePrice' => $part->purchase_price === null ? null : (float) $part->purchase_price,
                    'catalogPartId'   => $part->catalog_part_id !== null
                        ? $part->catalogPart?->uuid ?? ''
                        : '',
                    'unit'            => $part->unit,
                    'supplierId'      => $supplier?->uuid ?? '',
                    'supplierName'    => $supplier?->name ?? '',
                    'qty'             => (float) $part->quantity,
                    'price'           => (float) $part->price,
                    'returnedQty'     => (float) $part->returned_quantity,
                    'status'          => $part->status->value,
                    'approvedVersion' => $part->approved_version,
                    'comment'         => (string) ($part->comment ?? ''),
                    'changeNote'      => (string) ($part->change_note ?? ''),
                ], AgentDiscount::visibleTo($dto->role) ? AgentDiscount::partFields($part) : []);
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentPartMovements(RepairRequest $req): array
    {
        $partIds = $req->parts->pluck('id')->all();

        return PartMovement::query()
            ->whereIn('request_part_id', $partIds)
            ->orderBy('id')
            ->get()
            ->map(fn (PartMovement $movement) => [
                'id'        => $movement->uuid,
                'kind'      => $movement->kind->value,
                'qty'       => (float) $movement->quantity,
                'comment'   => $movement->comment,
                'createdAt' => $movement->created_at->toISOString(),
            ])
            ->all();
    }

    /* ─────────────────── Секции: периоды, история, вложения ─────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentStatusPeriods(RepairRequest $req): array
    {
        return $req->periods
            ->map(fn ($period) => [
                'id'        => $period->uuid,
                'stage'     => $period->stage->value,
                'enteredAt' => $period->entered_at->toISOString(),
                'exitedAt'  => $period->exited_at?->toISOString(),
                'seconds'   => $this->periodSeconds($period),
            ])
            ->all();
    }

    private function periodSeconds(object $period): int
    {
        $end = $period->exited_at ?? now();

        return max(0, (int) $period->entered_at->diffInSeconds($end));
    }

    private function currentPeriodId(RepairRequest $req): ?string
    {
        foreach ($req->periods as $period) {
            if ($period->exited_at === null) {
                return $period->uuid;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentHistory(RepairRequest $req, string $timezone): array
    {
        return $req->events
            ->map(fn ($event) => [
                'id'     => $event->uuid,
                'time'   => $event->occurred_at->setTimezone($timezone)->format('d.m.Y H:i'),
                'who'    => $event->actor_label,
                'text'   => $event->comment,
                'action' => $event->action,
                'stage'  => $event->stage->value,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentAttachments(RepairRequest $req): array
    {
        $periodIds = $req->attachments
            ->pluck('request_status_period_id')
            ->filter()
            ->unique()
            ->all();
        $periods = RequestStatusPeriod::query()
            ->whereIn('id', $periodIds)
            ->get()
            ->keyBy('id');

        return $req->attachments
            ->reject(fn ($attachment) => $attachment->purpose === AttachmentPurpose::GeneratedDocument)
            ->values()
            ->map(fn ($attachment) => [
                'id'       => $attachment->uuid,
                'name'     => $attachment->original_name,
                'mime'     => $attachment->mime_type->value,
                'size'     => $attachment->size,
                'purpose'  => $attachment->purpose->value,
                'periodId' => $periods->get($attachment->request_status_period_id)?->uuid,
                'linked'   => $attachment->request_event_id !== null,
            ])
            ->all();
    }

    /* ─────────────────── Секции: диагностика, согласования, счёт ─────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentDiagnosticReports(RepairRequest $req): array
    {
        return $req->diagnoses
            ->map(fn ($report) => [
                'id'            => $report->uuid,
                'conclusion'    => $report->conclusion,
                'mileage'       => $report->mileage,
                'attachmentIds' => $report->attachment_ids,
                'createdAt'     => $report->created_at->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentApprovals(RepairRequest $req): array
    {
        return $req->approvals
            ->map(fn ($approval) => [
                'id'              => $approval->uuid,
                'stage'           => $approval->stage->value,
                'decision'        => $approval->decision->value,
                'comment'         => $approval->comment,
                'estimateVersion' => $approval->estimate_version,
                'createdAt'       => $approval->created_at->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentAcceptanceAct(RepairRequest $req): ?array
    {
        $act = $req->acceptanceAct;

        if ($act === null) {
            return null;
        }

        return [
            'id'              => $act->uuid,
            'number'          => $act->number,
            'mode'            => $act->mode,
            'vehicle'         => $act->vehicle_snapshot,
            'contact'         => $act->contact_snapshot,
            'driver'          => $act->driver_snapshot,
            'mileage'         => $act->mileage,
            'fuelLevel'       => $act->fuel_level,
            'condition'       => $act->condition,
            'defectTypes'     => $act->defect_types,
            'diagramMarks'    => $act->diagram_marks,
            'attachmentId'    => $act->attachment?->uuid,
            'attachmentName'  => $act->attachment?->original_name,
            'lockedAt'        => $act->locked_at->toISOString(),
            'immutable'       => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentInvoice(RepairRequest $req): ?array
    {
        $invoice = $req->invoice;

        if ($invoice === null) {
            return null;
        }

        $documentId = $this->attachmentUuid($invoice->document_attachment_id);
        $paidDocumentId = $this->attachmentUuid($invoice->paid_document_attachment_id);

        return [
            'id'                        => $invoice->uuid,
            'systemId'                  => $invoice->id,
            'number'                    => $invoice->number,
            'amount'                    => (float) $invoice->amount,
            'status'                    => $invoice->status->value,
            'documentAttachmentId'      => $documentId,
            'paidDocumentAttachmentId'  => $paidDocumentId,
            'paidAmount'                => $invoice->paid_amount !== null ? (float) $invoice->paid_amount : null,
            'paidAt'                    => $invoice->paid_at?->toISOString(),
            'verifiedAt'                => $invoice->verified_at?->toISOString(),
        ];
    }

    private function attachmentUuid(?int $attachmentId): ?string
    {
        if ($attachmentId === null) {
            return null;
        }

        return Attachment::query()->find($attachmentId)?->uuid;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentWorkOrderItems(WorkOrder $order): array
    {
        return $order->items
            ->map(fn ($item) => [
                'kind'        => $item->kind->value,
                'name'        => $item->name,
                'qty'         => (float) $item->quantity,
                'price'       => (float) $item->price,
                'actualHours' => $item->actual_hours !== null ? (float) $item->actual_hours : null,
            ])
            ->all();
    }
}
