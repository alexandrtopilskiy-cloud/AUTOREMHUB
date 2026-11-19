<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class TransmissionsVehicleReferenceRequest extends ApiValidationRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'limit'  => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
