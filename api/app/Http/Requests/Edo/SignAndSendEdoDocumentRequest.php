<?php

declare(strict_types=1);

namespace App\Http\Requests\Edo;

use Illuminate\Foundation\Http\FormRequest;

final class SignAndSendEdoDocumentRequest extends FormRequest
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
            // CMS-подпись в base64. Минимум 100 символов — отсекает пустышки.
            // Верхняя граница — 10 МБ на подпись, что с запасом покрывает крупные УПД.
            'signature_base64' => ['required', 'string', 'min:100', 'max:10485760'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $sig = $this->input('signature_base64');

        if (is_string($sig)) {
            $this->merge([
                'signature_base64' => preg_replace('/\s+/', '', $sig) ?? $sig,
            ]);
        }
    }
}
