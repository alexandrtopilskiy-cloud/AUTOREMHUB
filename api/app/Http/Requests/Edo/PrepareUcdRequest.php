<?php

declare(strict_types=1);

namespace App\Http\Requests\Edo;

use Illuminate\Foundation\Http\FormRequest;

final class PrepareUcdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_document_uuid' => ['required', 'uuid'],
        ];
    }
}
