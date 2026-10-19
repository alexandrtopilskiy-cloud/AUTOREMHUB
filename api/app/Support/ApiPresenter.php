<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\PartMovement;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Supplier;
use App\Models\TimeNorm;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\RepairRequestCalculator;
use App\Services\Workflow;

final class ApiPresenter
{
    private array $recommendationSummaries = [];

    public function __construct(
        private readonly RepairRequestCalculator $repairRequestCalculator,
    ) {
    }

    /* ─────────────────── Публичный API ─────────────────── */

    /** Загружает ссылки рекомендаций одним запросом для реестра заявок. */
    public function preloadRecommendations(iterable $requests, User $user): void
    {
        $vehicleIds = collect($requests)->pluck('vehicle_id')->filter()->unique()->all();
        $this->recommendationSummaries = array_fill_keys($vehicleIds, []);
        if ($vehicleIds === []) {
            return;
        }
        foreach (\App\Models\Recommendation::forUser($user)->whereIn('vehicle_id', $vehicleIds)->orderByDesc('id')->get(['vehicle_id', 'uuid', 'number']) as $doc) {
            $this->recommendationSummaries[$doc->vehicle_id][] = ['id' => $doc->uuid, 'number' => $doc->number];
        }
    }

    private function recommendationSummary(RepairRequest $request, User $user): array
    {
        if ($request->vehicle_id === null) {
            return [];
        }
        return $this->recommendationSummaries[$request->vehicle_id]
            ?? \App\Models\Recommendation::forUser($user)->where('vehicle_id', $request->vehicle_id)->orderByDesc('id')->get(['uuid', 'number'])->map(fn ($doc) => ['id' => $doc->uuid, 'number' => $doc->number])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function document(RepairRequest $request, User $user): array
    {
        $request->load([
            'vehicleRecord.organization',
            'vehicleRecord.modelReference',
            'contactRecord',
            'driverRecord',
            'stationRecord',
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
        ]);

        $data = $this->resolveData($request);
        $vehicle = $request->vehicleRecord;
        $contact = $request->contactRecord;
        $driver = $request->driverRecord;
        $station = $request->stationRecord;
        $timezone = (string) config('sto.service_timezone');

        return CommercialVisibility::forUser(array_merge($data, [
            'id'                     => $request->uuid,
            'uuid'                   => $request->uuid,
            'systemId'               => $request->id,
            'number'                 => $request->number,
            'revision'               => $request->revision,
            'stage'                  => $request->stage->value,
            'createdAt'              => $request->created_at->toISOString(),
            'updatedAt'              => $request->updated_at->toISOString(),
            'estimateVersion'        => $request->estimate_version,
            'reviewContext'          => $request->review_context,
            'declinedStationIds' => $request->data['declinedStationIds'] ?? [],
            'statusLabel' => $request->stage === RepairStage::ManagerRouting
                ? match ($request->review_context) {
                    'station_changes' => 'Согласование работ / з/ч',
                    'station_declined' => 'Маршрутизация УК (отказ СТО)',
                    default => $request->stage->label(),
                } : $request->stage->label(),
            'reviewBaselineVersion'  => $request->review_baseline_version,
            'vehicleId'              => $vehicle?->uuid ?? '',
            'vehicle'                => $vehicle?->name ?? '',
            'make'                   => $vehicle?->make ?? '',
            'model'                  => $vehicle?->model ?? '',
            'vehicleModelId'         => $vehicle?->modelReference?->uuid ?? '',
            'vin'                    => $vehicle?->vin ?? '',
            'plate'                  => $vehicle?->plate ?? '',
            'year'                   => $vehicle?->year,
            'company'                => $user->role === UserRole::Station
                ? ''
                : ($vehicle?->organization?->name ?? ''),
            'previousServiceMileage' => $request->previous_service_mileage,
            'previousServiceAt'      => $request->previous_service_at?->toISOString(),
            'laborRateType' => ($data['laborRateType'] ?? '') ?: 'general',
            'laborRates' => $user->role === UserRole::Manager ? [
                'general' => $vehicle?->laborRate('general'),
                'official' => $vehicle?->laborRate('official'),
                'other' => $vehicle?->laborRate('other'),
            ] : [],
            'laborHourRate'          => $user->role !== UserRole::Customer
                ? ($vehicle?->laborRate($data['laborRateType'] ?? 'general') !== null
                    ? (float) $vehicle->laborRate($data['laborRateType'] ?? 'general') : null)
                : null,
            'contactId'              => $contact?->uuid ?? '',
            'contact'                => $this->formatContact($request, $data),
            'contactDetails'         => $request->contact_snapshot ?? $contact?->snapshot(),
            'driverId'               => $driver?->uuid ?? '',
            'driver'                 => $this->formatDriver($request),
            'driverDetails'          => $request->driver_snapshot ?? $driver?->snapshot(),
            'stationId'              => $station?->uuid ?? '',
            'station'                => $this->formatStation($station),
            'reasonId'               => $request->reasonRecord?->uuid ?? '',
            'reasonSubtypeId'        => $request->reasonSubtype?->uuid ?? '',
            'reason'                 => $request->reasonRecord?->name ?? '',
            'diagnostics'            => $request->reasonSubtype?->name ?? '',
            'plannedCompletionAt'    => $this->formatPlannedCompletionAt($request, $timezone),
            'repairCompletedAt'      => $request->repair_completed_at?->toISOString(),
            'overdueSeconds'         => $this->calculateOverdueSeconds($request),
            'statusPeriods'          => $this->presentStatusPeriods($request),
            'recommendations' => $this->recommendationSummary($request, $user),
            'works'                  => $this->presentWorks($request),
            'partMovements'          => $this->presentPartMovements($request),
            'parts'                  => $this->presentParts($request),
            'total'                  => (float) $this->repairRequestCalculator->total($request),
            'history'                => $this->presentHistory($request, $timezone),
            'attachments'            => $this->presentAttachments($request),
            'currentPeriodId'        => $this->currentPeriodId($request),
            'diagnosticReports'      => $this->presentDiagnosticReports($request),
            'approvals'              => $this->presentApprovals($request),
            'workOrder'              => $this->workOrder($request),
            'invoice'                => $this->presentInvoice($request),
            'acceptanceAct'          => $this->presentAcceptanceAct($request),
            'permissions' => array_merge(Workflow::permissions($request, $user), [
                'canViewRepairEstimate' => $user->role !== UserRole::Customer || $request->stage->value >= RepairStage::CustomerApproval->value
                    || $request->periods->contains(fn ($period) => (int) $period->getRawOriginal('stage') >= RepairStage::CustomerApproval->value),
            ]),
        ]), $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workOrder(RepairRequest $request): ?array
    {
        $order = $request->workOrder;

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
    private function resolveData(RepairRequest $request): array
    {
        return array_replace(
            array_fill_keys(Workflow::TEXT_FIELDS, ''),
            array_intersect_key($request->data, array_flip(Workflow::TEXT_FIELDS)),
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
    private function formatContact(RepairRequest $request, array $data): string
    {
        $snapshot = $request->contact_snapshot;

        if (is_array($snapshot)) {
            return implode(' · ', array_filter([
                $snapshot['fullName'] ?? '',
                $snapshot['phone'] ?? '',
            ]));
        }

        return (string) ($data['contact'] ?? '');
    }

    private function formatDriver(RepairRequest $request): string
    {
        $snapshot = $request->driver_snapshot;

        if (!is_array($snapshot)) {
            return '';
        }

        return implode(' · ', array_filter([
            $snapshot['fullName'] ?? '',
            $snapshot['phone'] ?? '',
        ]));
    }

    private function formatPlannedCompletionAt(RepairRequest $request, string $timezone): string
    {
        if ($request->planned_completion_at === null) {
            return '';
        }

        return $request->planned_completion_at
            ->setTimezone($timezone)
            ->format('Y-m-d\TH:i');
    }

    private function calculateOverdueSeconds(RepairRequest $request): int
    {
        if (
            $request->planned_completion_at === null
            || $request->stage->value < RepairStage::RepairInProgress->value
        ) {
            return 0;
        }

        $end = $request->repair_completed_at ?? now();

        if (!$end->greaterThan($request->planned_completion_at)) {
            return 0;
        }

        return (int) $request->planned_completion_at->diffInSeconds($end);
    }

    /* ─────────────────── Секции: workLines, parts, movements ─────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentWorks(RepairRequest $request): array
    {
        $workTypeIds = $request->workLines->pluck('work_type_id')->filter()->unique()->all();
        $timeNormIds = $request->workLines->pluck('time_norm_id')->filter()->unique()->all();

        $workTypes = WorkType::query()->whereIn('id', $workTypeIds)->get()->keyBy('id');
        $timeNorms = TimeNorm::query()->with(TimeNormDescription::RELATIONS)->whereIn('id', $timeNormIds)->get()->keyBy('id');

        return $request->workLines
            ->map(fn ($work) => [
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
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentParts(RepairRequest $request): array
    {
        $supplierIds = $request->parts->pluck('supplier_id')->filter()->unique()->all();
        $suppliers = Supplier::query()->whereIn('id', $supplierIds)->get()->keyBy('id');

        return $request->parts
            ->map(function ($part) use ($suppliers): array {
                $supplier = $suppliers->get($part->supplier_id);

                return [
                    'id'              => $part->uuid,
                    'systemId'        => $part->id,
                    'name'            => $part->name,
                    'article'         => $part->article,
                    'manufacturer'    => $part->manufacturer_snapshot ?? $part->catalogPart?->manufacturer ?? '',
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
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentPartMovements(RepairRequest $request): array
    {
        $partIds = $request->parts->pluck('id')->all();

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
    private function presentStatusPeriods(RepairRequest $request): array
    {
        return $request->periods
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

    private function currentPeriodId(RepairRequest $request): ?string
    {
        foreach ($request->periods as $period) {
            if ($period->exited_at === null) {
                return $period->uuid;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentHistory(RepairRequest $request, string $timezone): array
    {
        return $request->events
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
    private function presentAttachments(RepairRequest $request): array
    {
        $periodIds = $request->attachments->pluck('request_status_period_id')->filter()->unique()->all();
        $periods = RequestStatusPeriod::query()->whereIn('id', $periodIds)->get()->keyBy('id');

        return $request->attachments
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
    private function presentDiagnosticReports(RepairRequest $request): array
    {
        return $request->diagnoses
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
    private function presentApprovals(RepairRequest $request): array
    {
        return $request->approvals
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
    private function presentAcceptanceAct(RepairRequest $request): ?array
    {
        $act = $request->acceptanceAct;

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
            'lockedAt'        => $act->locked_at->toISOString(),
            'immutable'       => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentInvoice(RepairRequest $request): ?array
    {
        $invoice = $request->invoice;

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
