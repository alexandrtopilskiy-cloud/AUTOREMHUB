<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerationsVehicleReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'modelId' => ['required', 'uuid'],
            'search'  => ['sometimes', 'string', 'max:255'],
            'limit'   => ['sometimes', 'integer', 'between:1,200'],
        ];
    }
}
