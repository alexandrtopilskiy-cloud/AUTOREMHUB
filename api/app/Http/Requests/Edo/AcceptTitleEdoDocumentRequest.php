<?php

declare(strict_types=1);

namespace App\Http\Requests\Edo;

use Illuminate\Foundation\Http\FormRequest;

final class AcceptTitleEdoDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signer.inn'               => ['required', 'string', 'max:12'],
            'signer.organization_name' => ['required', 'string', 'max:1000'],
            'signer.last_name'         => ['required', 'string', 'max:60'],
            'signer.first_name'        => ['required', 'string', 'max:60'],
            'signer.middle_name'       => ['nullable', 'string', 'max:60'],
            'signer.title'             => ['required', 'string', 'max:128'],
            'signer.authority'         => ['required', 'string', 'max:255'],
            'signer.authority_scope'   => ['required', 'integer', 'min:0', 'max:10'],
            'signer.condition'         => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }
}
