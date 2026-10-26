<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VehicleAcceptanceAct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VehicleAcceptanceAct */
final class AcceptanceActResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->uuid,
            'number'          => $this->number,
            'mode'            => $this->mode,
            'vehicle'         => $this->vehicle_snapshot,
            'contact'         => $this->contact_snapshot,
            'driver'          => $this->driver_snapshot,
            'mileage'         => $this->mileage,
            'fuelLevel'       => $this->fuel_level,
            'condition'       => $this->condition,
            'defectTypes'     => $this->defect_types,
            'diagramMarks'    => $this->diagram_marks,
            'attachmentId'    => $this->attachment?->uuid,
            'lockedAt'        => $this->locked_at->toISOString(),
            'immutable'       => true,
        ];
    }
}
