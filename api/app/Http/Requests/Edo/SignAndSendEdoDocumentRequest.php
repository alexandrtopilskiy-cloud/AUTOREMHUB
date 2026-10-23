<?php

declare(strict_types=1);

namespace App\Http\Requests\Edo;

use App\Http\Requests\ApiValidationRequest;

final class SignAndSendEdoDocumentRequest extends ApiValidationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // CMS-подпись в base64. Минимум 100 символов — отсекает пустышки.
            // Верхняя граница — 10 МБ на подпись.
            'signature_base64' => ['required', 'string', 'min:100', 'max:10485760'],
            'certificateFingerprint' => ['nullable'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->stripWhitespace(['signature_base64']);
    }
}
