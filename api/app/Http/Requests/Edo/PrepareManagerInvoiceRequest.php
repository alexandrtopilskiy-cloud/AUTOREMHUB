<?php

declare(strict_types=1);

namespace App\Http\Requests\Edo;

use App\Enums\Edo\EdoDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrepareManagerInvoiceRequest extends FormRequest
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
            'document_type' => [
                'sometimes',
                'string',
                Rule::in([
                    EdoDocumentType::UpdSf->value,
                    EdoDocumentType::UpdSfDop->value,
                ]),
            ],
        ];
    }

    public function documentType(): EdoDocumentType
    {
        $value = $this->validated('document_type');

        return $value === null
            ? EdoDocumentType::UpdSf
            : EdoDocumentType::from($value);
    }
}
