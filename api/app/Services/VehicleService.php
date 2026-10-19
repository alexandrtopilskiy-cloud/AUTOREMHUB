<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\PersonKind;
use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Models\VehicleServiceRecord;
use App\Models\WorkOrderItem;
use App\Support\RussianPhone;
use Illuminate\Support\Collection;

final class VehicleService
{
    public function previousMileage(
        Vehicle $vehicle,
        User $user,
        ?int $excludeRequestId = null,
    ): ?int {
        return $this->previousMileageDetails($vehicle, $user, $excludeRequestId)['mileage'];
    }

    /**
     * Возвращает пробег и дату из последней полностью закрытой заявки.
     * Если такой заявки нет, использует историю работ или базовые данные автомобиля.
     *
     * @return array{mileage: int|null, recordedAt: string|null}
     */
    public function previousMileageDetails(
        Vehicle $vehicle,
        User $user,
        ?int $excludeRequestId = null,
    ): array {
        $requestQuery = RepairRequest::forUser($user)
            ->where('vehicle_id', $vehicle->id)
            ->where('stage', \App\Enums\RepairStage::Closed->value)
            ->whereRaw("(data->>'mileage') ~ '^[0-9]{1,9}$'");

        if ($excludeRequestId !== null) {
            $requestQuery->where('id', '!=', $excludeRequestId);
        }

        $previousRequest = $requestQuery
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if ($previousRequest !== null
            && (int) $previousRequest->data['mileage'] >= (int) ($vehicle->baseline_service_mileage ?? -1)) {
            return [
                'mileage'    => (int) $previousRequest->data['mileage'],
                'recordedAt' => $previousRequest->updated_at->toISOString(),
            ];
        }

        $record = VehicleServiceRecord::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('is_maintenance', true)
            ->whereIn('repair_request_id', RepairRequest::forUser($user)
                ->where('stage', \App\Enums\RepairStage::Closed->value)->select('id'))
            ->orderByDesc('performed_at')
            ->first();

        if ($record !== null && $record->mileage >= (int) ($vehicle->baseline_service_mileage ?? -1)) {
            return [
                'mileage'    => $record->mileage,
                'recordedAt' => $record->performed_at->toISOString(),
            ];
        }

        return [
            'mileage'    => $vehicle->baseline_service_mileage,
            'recordedAt' => $vehicle->baseline_service_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(
        Vehicle $vehicle,
        User $user,
        ?int $excludeRequestId = null,
    ): array {
        $vehicle->loadMissing('organization', 'contacts', 'modelReference.make');
        $previous = $this->previousMileageDetails($vehicle, $user, $excludeRequestId);
        $people = $vehicle->contacts
            ->where('active', true)
            ->sortBy(fn (VehicleContact $person): string => $person->fullName())
            ->map(fn (VehicleContact $person): array => $this->presentContact($person))
            ->values();

        return [
            'id'                    => $vehicle->uuid,
            'uuid'                  => $vehicle->uuid,
            'systemId'              => $vehicle->id,
            'kind'                  => CatalogKind::Vehicles->value,
            'vehicle'               => $vehicle->name,
            'make'                  => $vehicle->make,
            'model'                 => $vehicle->model,
            'vehicleMakeId'         => $vehicle->modelReference?->make?->uuid,
            'vehicleModelId'        => $vehicle->modelReference?->uuid,
            'modelYearFrom' => $vehicle->modelReference?->model_year_from,
            'modelYearTo' => $vehicle->modelReference?->model_year_to,
            'bodyCode' => $vehicle->modelReference?->body_code,
            'engineIndex' => $vehicle->modelReference?->engine_index,
            'horsepower' => $vehicle->modelReference?->horsepower,
            'engineVolumeLiters' => $vehicle->modelReference?->engine_volume_liters,
            'transmission' => $vehicle->modelReference?->transmission,
            'driveType' => $vehicle->modelReference?->drive_type,
            'engineType' => $vehicle->modelReference?->engine_type,
            'technicalReference' => $vehicle->modelReference === null ? null
                : app(VehicleReferenceService::class)->presentModel($vehicle->modelReference),
            'laborHourRateOfficial' => $user->role === UserRole::Manager
                && $vehicle->labor_hour_rate_official !== null ? (float) $vehicle->labor_hour_rate_official : null,
            'laborHourRateOther' => $user->role === UserRole::Manager
                && $vehicle->labor_hour_rate_other !== null ? (float) $vehicle->labor_hour_rate_other : null,
            'vin'                   => $vehicle->vin,
            'plate'                 => $vehicle->plate,
            'year'                  => $vehicle->year,
            'company'               => $vehicle->organization->name,
            'organizationId'        => $vehicle->organization->uuid,
            'baselineServiceMileage' => $vehicle->baseline_service_mileage,
            'baselineServiceAt'      => $vehicle->baseline_service_at?->toISOString(),
            'laborRateCoefficient'    => (float) $vehicle->labor_rate_coefficient,
            'laborHourRate'           => $user->role === UserRole::Manager
                ? ($vehicle->labor_hour_rate !== null ? (float) $vehicle->labor_hour_rate : null)
                : null,
            'previousServiceMileage' => $previous['mileage'],
            'previousServiceAt'      => $previous['recordedAt'],
            'contacts'               => $people
                ->where('kind', PersonKind::Contact->value)
                ->values()
                ->all(),
            'drivers'                => $people
                ->where('kind', PersonKind::Driver->value)
                ->values()
                ->all(),
            'contactNames'           => $people
                ->where('kind', PersonKind::Contact->value)
                ->pluck('fullName')
                ->implode(', '),
            'driverNames'            => $people
                ->where('kind', PersonKind::Driver->value)
                ->pluck('fullName')
                ->implode(', '),
            'permissions'           => [
                'canEdit' => in_array(
                    $user->role,
                    [UserRole::Customer, UserRole::Manager],
                    true,
                ),
                'requiresApproval' => $user->role === UserRole::Customer,
                'canEditRate'      => $user->role === UserRole::Manager,
                'canDelete'        => in_array(
                    $user->role,
                    [UserRole::Customer, UserRole::Manager],
                    true,
                ),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function contacts(Vehicle $vehicle): array
    {
        return $vehicle->contacts()
            ->where('kind', PersonKind::Contact->value)
            ->where('active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name')
            ->get()
            ->map(fn (VehicleContact $contact): array => $this->presentContact($contact))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function drivers(Vehicle $vehicle): array
    {
        return $vehicle->contacts()
            ->where('kind', PersonKind::Driver->value)
            ->where('active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name')
            ->get()
            ->map(fn (VehicleContact $driver): array => $this->presentContact($driver))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentContact(VehicleContact $contact): array
    {
        $contact->loadMissing(['vehicle', 'organizationPerson']);

        return [
            'id'         => $contact->uuid,
            'uuid'       => $contact->uuid,
            'systemId'   => $contact->id,
            'vehicleId'  => $contact->vehicle->uuid,
            'vin'        => $contact->vehicle->vin,
            'personId'   => $contact->organizationPerson?->uuid ?? '',
            'kind'       => $contact->kind->value,
            'lastName'   => $contact->last_name,
            'firstName'  => $contact->first_name,
            'middleName' => $contact->middle_name ?? '',
            'fullName'   => $contact->fullName(),
            'phone'      => RussianPhone::format($contact->phone),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statistics(Vehicle $vehicle, User $user): array
    {
        $records = VehicleServiceRecord::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('repair_request_id', RepairRequest::forUser($user)
                ->where('stage', \App\Enums\RepairStage::Closed->value)->select('id'))
            ->orderByDesc('performed_at')
            ->get();

        $items = WorkOrderItem::query()
            ->whereIn('id', $records->pluck('work_order_item_id'))
            ->get()
            ->keyBy('id');

        $history = $records
            ->map(fn (VehicleServiceRecord $record) => $this->presentHistoryRow($record, $items))
            ->all();

        return [
            'vehicle'    => $this->present($vehicle, $user),
            'statistics' => [
                'completedWorks'     => count($history),
                'maintenanceVisits'  => $this->countMaintenanceVisits($records),
                'maxRecordedMileage' => $records->max('mileage'),
                'history'            => $history,
            ],
        ];
    }

    /**
     * @param Collection<int, WorkOrderItem> $items
     *
     * @return array<string, mixed>
     */
    private function presentHistoryRow(VehicleServiceRecord $record, Collection $items): array
    {
        $item = $items->get($record->work_order_item_id);

        return [
            'id'            => $record->uuid,
            'mileage'       => $record->mileage,
            'performedAt'   => $record->performed_at->toISOString(),
            'isMaintenance' => $record->is_maintenance,
            'work'          => $item?->name ?? '',
            'qty'           => (float) ($item?->quantity ?? 0),
            'price'         => (float) ($item?->price ?? 0),
            'normHours'     => $item?->norm_hours !== null ? (float) $item->norm_hours : null,
            'actualHours'   => $item?->actual_hours !== null ? (float) $item->actual_hours : null,
        ];
    }

    /**
     * Количество визитов по регламентному ТО.
     *
     * @param Collection<int, VehicleServiceRecord> $records
     */
    private function countMaintenanceVisits(Collection $records): int
    {
        return $records
            ->where('is_maintenance', true)
            ->pluck('repair_request_id')
            ->unique()
            ->count();
    }
}
