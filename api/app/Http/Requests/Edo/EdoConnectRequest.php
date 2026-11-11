<?php

declare(strict_types=1);

namespace App\Http\Requests\Edo;

use App\Enums\Edo\EdoProvider;
use App\Http\Requests\ApiValidationRequest;

class EdoConnectRequest extends ApiValidationRequest
{
    /**
     * Правила валидации в зависимости от выбранного провайдера.
     */
    public function rules(): array
    {
        $provider = config('edo.default');

        return match ($provider) {
            EdoProvider::Diadoc->value => [
                'client_id'     => ['required', 'string'],
                'refresh_token' => ['required', 'string'],
            ],
            EdoProvider::Sbis->value => [
//                'login'    => ['required', 'string'],
                'client_id'    => ['required', 'string'],
//                'password' => ['required', 'string'],
                'refresh_token' => ['required', 'string'],
            ],
            default => [],
        };
    }

    /**
     * Кастомные сообщения об ошибках.
     */
    public function messages(): array
    {
        return [
            'client_id.required'     => 'Необходимо указать BoxId (Идентификатор ящика).',
            'refresh_token.required' => 'Необходимо указать Refresh Token.',
            'login.required'         => 'Необходимо указать логин для СБИС.',
            'password.required'      => 'Необходимо указать пароль для СБИС.',
        ];
    }
}
