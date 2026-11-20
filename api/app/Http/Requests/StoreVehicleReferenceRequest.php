<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class StoreVehicleReferenceRequest extends ApiValidationRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'record' => ['required', 'array'],
        ];
    }
}
