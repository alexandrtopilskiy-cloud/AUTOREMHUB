<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Возвращаем JSON-ошибку в едином формате API, а не редирект.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Ошибка валидации',
                'fields' => $validator->errors()->toArray(),
            ],
        ], 422));
    }

    /**
     * Убираем пробелы из base64-строк (подписи часто приходят с переносами).
     *
     * @param array<string> $fields
     */
    protected function stripWhitespace(array $fields): void
    {
        $merged = [];

        foreach ($fields as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $merged[$field] = preg_replace('/\s+/', '', $value) ?? $value;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}
