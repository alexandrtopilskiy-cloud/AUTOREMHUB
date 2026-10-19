<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LookupVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'vin'   => ['nullable', 'string', 'max:17'],
            'plate' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (!$this->filled('vin') && !$this->filled('plate')) {
                $validator->errors()->add('vin', 'Передайте vin или plate');
            }
        });
    }
}