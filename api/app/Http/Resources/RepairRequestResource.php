<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Services\RepairRequestCalculator;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RepairRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        // Загружаем все необходимые связи (для избежания N+1)
        $this->resource->loadMissing([
            'vehicleRecord.organization',
            'vehicleRecord.modelReference',
            'contactRecord',
            'driverRecord',
            'stationRecord',
            'reasonRecord',
            'reasonSubtype',
//            'workLines.workType',
//            'workLines.timeNorm',
            'parts.catalogPart',
            'parts.supplier',
            'events',
            'periods',
            'attachments',
            'diagnoses',
            'approvals',
            'workOrder.items',
//            'invoice.documentAttachment',
//            'invoice.paidDocumentAttachment',
            'acceptanceAct.attachment',
        ]);

        $vehicle = $this->vehicleRecord;
        $contact = $this->contactRecord;
        $driver = $this->driverRecord;
        $station = $this->stationRecord;
        $timezone = (string) config('sto.service_timezone');

        $data = array_replace(
            array_fill_keys(Workflow::TEXT_FIELDS, ''),
            array_intersect_key($this->data ?? [], array_flip(Workflow::TEXT_FIELDS)),
        );

        // Считаем total (один раз)
        $total = (float) app(RepairRequestCalculator::class)->total($this->resource);

        return [
            'id'                     => $this->uuid,
            'uuid'                   => $this->uuid,
            'systemId'               => $this->id,
            'number'                 => $this->number,
            'revision'               => $this->revision,
            'stage'                  => $this->stage->value,
            'createdAt'              => $this->created_at->toISOString(),
            'updatedAt'              => $this->updated_at->toISOString(),
            'estimateVersion'        => $this->estimate_version,
            'reviewContext'          => $this->review_context,
            'declinedStationIds'     => $this->data['declinedStationIds'] ?? [],
            'statusLabel'            => $this->stage === RepairStage::ManagerRouting
                ? match ($this->review_context) {
                    'station_changes' => 'Согласование работ / з/ч',
                    'station_declined' => 'Маршрутизация УК (отказ СТО)',
                    default => $this->stage->label(),
                } : $this->stage->label(),
            'reviewBaselineVersion'  => $this->review_baseline_version,

            // Vehicle
            'vehicleId'              => $vehicle?->uuid ?? '',
            'vehicle'                => $vehicle?->name ?? '',
            'make'                   => $vehicle?->make ?? '',
            'model'                  => $vehicle?->model ?? '',
            'vehicleModelId'         => $vehicle?->modelReference?->uuid ?? '',
            'vin'                    => $vehicle?->vin ?? '',
            'plate'                  => $vehicle?->plate ?? '',
            'year'                   => $vehicle?->year,
            'company'                => $user->role === UserRole::Station ? '' : ($vehicle?->organization?->name ?? ''),
            'previousServiceMileage' => $this->previous_service_mileage,
            'previousServiceAt'      => $this->previous_service_at?->toISOString(),

            // Rates
            'laborRateType' => ($data['laborRateType'] ?? '') ?: 'general',
            'laborRates' => $user->role === UserRole::Manager ? [
                'general' => $vehicle?->laborRate('general'),
                'official' => $vehicle?->laborRate('official'),
                'other' => $vehicle?->laborRate('other'),
            ] : [],
            'laborHourRate' => $user->role !== UserRole::Customer
                ? ($vehicle?->laborRate($data['laborRateType'] ?? 'general') !== null
                ? (float) $vehicle->laborRate($data['laborRateType'] ?? 'general') : null)
                : null,

            // Contact & Driver
            'contactId'              => $contact?->uuid ?? '',
            'contact'                => $this->formatContact($data),
            'contactDetails'         => $this->contact_snapshot ?? $contact?->snapshot(),
            'driverId'               => $driver?->uuid ?? '',
            'driver'                 => $this->formatDriver(),
            'driverDetails'          => $this->driver_snapshot ?? $driver?->snapshot(),

            // Station & Reason
            'stationId'              => $station?->uuid ?? '',
            'station'                => $this->formatStation($station),
            'reasonId'               => $this->reasonRecord?->uuid ?? '',
            'reasonSubtypeId'        => $this->reasonSubtype?->uuid ?? '',
            'reason'                 => $this->reasonRecord?->name ?? '',
            'diagnostics'            => $this->reasonSubtype?->name ?? '',

            // Dates & Overdue
            'plannedCompletionAt'    => $this->planned_completion_at ? $this->planned_completion_at->setTimezone($timezone)->format('Y-m-d\TH:i') : '',
            'repairCompletedAt'      => $this->repair_completed_at?->toISOString(),
            'overdueSeconds'         => $this->calculateOverdueSeconds(),

            // Relations (Resources)
            'statusPeriods'          => RepairRequestStatusPeriodResource::collection($this->periods),
            'recommendations'        => [], // TODO: если нужно, вынести в RecommendationResource
            'works'                  => WorkResource::collection($this->workLines),
            'partMovements'          => [], // TODO: если нужно, вынести в PartMovementResource
            'parts'                  => PartResource::collection($this->parts),
            'total'                  => $total,
            'history'                => RepairRequestEventResource::collection($this->events),
            'attachments'            => AttachmentResource::collection($this->attachments), // Создадим ниже
            'currentPeriodId'        => $this->periods->first(fn($p) => $p->exited_at === null)?->uuid,
            'diagnosticReports'      => DiagnosticReportResource::collection($this->diagnoses),
            'approvals'              => RepairRequestApprovalResource::collection($this->approvals),
            'workOrder'              => $this->workOrder ? (new WorkOrderResource($this->resource))->toArray($request)['workOrder'] : null,
            'invoice'                => $this->invoice ? (new InvoiceResource($this->invoice))->toArray($request) : null,
            'acceptanceAct'          => $this->acceptanceAct ? (new AcceptanceActResource($this->acceptanceAct))->toArray($request) : null,

            // Permissions
            'permissions' => array_merge(Workflow::permissions($this->resource, $user), [
                'canViewRepairEstimate' => $user->role !== UserRole::Customer || $this->stage->value >= RepairStage::CustomerApproval->value
                    || $this->periods->contains(fn ($period) => (int) $period->getRawOriginal('stage') >= RepairStage::CustomerApproval->value),
            ]),
        ];
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
    private function formatContact(array $data): string
    {
        $snapshot = $this->contact_snapshot;

        if (is_array($snapshot)) {
            return implode(' · ', array_filter([
                $snapshot['fullName'] ?? '',
                $snapshot['phone'] ?? '',
            ]));
        }

        return (string) ($data['contact'] ?? '');
    }

    private function formatDriver(): string
    {
        $snapshot = $this->driver_snapshot;

        if (!is_array($snapshot)) {
            return '';
        }

        return implode(' · ', array_filter([
            $snapshot['fullName'] ?? '',
            $snapshot['phone'] ?? '',
        ]));
    }

    private function calculateOverdueSeconds(): int
    {
        if ($this->planned_completion_at === null || $this->stage->value < RepairStage::RepairInProgress->value) {
            return 0;
        }

        $end = $this->repair_completed_at ?? now();

        if (!$end->greaterThan($this->planned_completion_at)) {
            return 0;
        }

        return (int) $this->planned_completion_at->diffInSeconds($end);
    }
}
