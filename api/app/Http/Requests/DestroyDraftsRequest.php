<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class DestroyDraftsRequest extends ApiValidationRequest
{
    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
