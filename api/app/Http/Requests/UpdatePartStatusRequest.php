<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RequestPartStatus;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePartStatusRequest extends FormRequest
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
}
