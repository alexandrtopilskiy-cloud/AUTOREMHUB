<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class EnginesVehicleReferenceRequest extends ApiValidationRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'modelId'      => ['required', 'uuid'],
            'generationId' => ['sometimes', 'nullable', 'uuid'],
            'search'       => ['sometimes', 'string', 'max:255'],
            'limit'        => ['sometimes', 'integer', 'between:1,200'],
        ];
    }
}
