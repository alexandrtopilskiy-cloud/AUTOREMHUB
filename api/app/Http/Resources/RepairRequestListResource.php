<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTO\UserDto;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка реестра ЗР: только то, что нужно таблице «Заявки на ремонт»,
 * счётчикам и выбору черновиков. Полная ЗР — GET /requests/{id}.
 */
final class RepairRequestListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dto = UserDto::fromUserModel($request->user());
        $vehicle = $this->vehicleRecord;
        $station = $this->stationRecord;
        $permissions = Workflow::permissions($this->resource, $dto);

        return [
            'id'            => $this->uuid,
            'uuid'          => $this->uuid,
            'systemId'      => $this->id,
            'summary'       => true,
            'number'        => $this->number,
            'revision'      => $this->revision,
            'stage'         => $this->stage->value,
            'createdAt'     => $this->created_at->toISOString(),
            'updatedAt'     => $this->updated_at->toISOString(),
            'statusLabel'   => Workflow::statusLabel($this->resource),
            'ownerLabel'    => Workflow::ownerLabel($this->resource),
            'vehicleId'     => $vehicle?->uuid ?? '',
            'vehicle'       => $vehicle?->name ?? '',
            'plate'         => $vehicle?->plate ?? '',
            'company'       => $dto->role === UserRole::Station ? '' : ($vehicle?->organization?->name ?? ''),
            'stationId'     => $station?->uuid ?? '',
            'station'       => $station ? implode(' · ', array_filter([$station->name, $station->address])) : '',
            'stationDetails' => $station ? ['name' => (string) $station->name] : null,
            'overdueSeconds' => $this->overdueSeconds(),
            'total'         => round((float) ($this->registry_total ?? 0), 2),
            'permissions'   => [
                'requiresAction' => $permissions['requiresAction'],
                'canAdvance'     => $permissions['canAdvance'],
                'canDeleteDraft' => $permissions['canDeleteDraft'],
            ],
        ];
    }

    private function overdueSeconds(): int
    {
        if ($this->planned_completion_at === null || $this->stage->value < RepairStage::RepairInProgress->value) {
            return 0;
        }

        $end = $this->repair_completed_at ?? now();

        return $end->greaterThan($this->planned_completion_at)
            ? (int) $this->planned_completion_at->diffInSeconds($end)
            : 0;
    }
}
