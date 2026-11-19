<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SelectionVehicleReferenceRequest extends ApiValidationRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'makeId' => ['required', 'uuid'],
            'step' => ['required', 'string'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['string', 'max:500'],
        ];
    }
}
