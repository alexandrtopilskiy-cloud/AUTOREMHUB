<?php

declare(strict_types=1);

namespace App\Http\Requests;


final class ModelsVehicleReferenceRequest extends ApiValidationRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'uniqueNames' => ['sometimes', 'boolean'],
            'makeId'      => ['required', 'uuid'],
            'search'      => ['sometimes', 'string', 'max:255'],
            'limit'       => ['sometimes', 'integer', 'between:1,200'],
        ];
    }
}
