<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RequestPartStatus;
use Illuminate\Foundation\Http\FormRequest;
use Override;

final class UpdatePartStatusRequest extends ApiValidationRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer'],
            'status'   => [
                'required',
                'in:' . implode(',', [
                    RequestPartStatus::Ordered->value,
                    RequestPartStatus::Received->value,
                ]),
            ],
            'comment'  => ['required', 'string', 'max:10000'],
        ];
    }

    #[Override]
    public function messages()
    {
        return [
            'comment' => 'Укажите комментарий'
        ];
    }
}
