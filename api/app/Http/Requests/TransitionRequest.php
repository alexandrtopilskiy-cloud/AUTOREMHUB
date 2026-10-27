<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class TransitionRequest extends ApiValidationRequest
{
    public function rules(): array
    {
        return [
            'revision'               => ['required', 'integer'],
            'comment'                => ['sometimes', 'string', 'max:10000'],
            'confirmDeclinedStation' => ['sometimes', 'boolean'],
            'attachmentIds'          => ['sometimes', 'array', 'list', 'max:20'],
            'attachmentIds.*'        => ['required', 'uuid'],
        ];
    }
}
