<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class AcceptVehicleRequest extends ApiValidationRequest
{
    public function rules(): array
    {
        return [
            'revision'               => ['required', 'integer'],
            'mode'                   => ['required', 'string', 'in:electronic,uploaded'],
            'attachmentId'           => ['sometimes', 'nullable', 'uuid'],
            'mileage'                => ['required', 'integer', 'min:0', 'max:999999999'],
            'fuelLevel'              => ['sometimes', 'nullable', 'string', 'in:empty,quarter,half,three_quarters,full'],
            'condition'              => ['sometimes', 'nullable', 'string', 'max:10000'],
            'defectTypes'            => ['sometimes', 'array', 'list', 'max:20'],
            'defectTypes.*'          => ['required', 'string', 'in:scratch,dent,chip,glass_crack,corrosion,other'],
            'diagramMarks'           => ['sometimes', 'array', 'list', 'max:50'],
            'diagramMarks.*.zone'    => ['required', 'string', 'in:front,rear,left,right,roof,windshield,rear_glass'],
            'diagramMarks.*.type'    => ['required', 'string', 'in:scratch,dent,chip,glass_crack,corrosion,other'],
            'diagramMarks.*.comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
