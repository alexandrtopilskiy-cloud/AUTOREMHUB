<?php

declare(strict_types=1);

namespace App\Http\Requests\Edo;

use App\Enums\Edo\EdoDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrepareEdoDocumentRequest extends FormRequest
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
            'document_type' => [
                'required',
                'string',
                // Пока поддерживаем только УПД.СЧФ через structured flow.
                Rule::in([EdoDocumentType::UpdSf->value]),
            ],
            'counterparty_organization_id' => [
                'required',
                'integer',
                'exists:organizations,id',
            ],
        ];
    }
}
