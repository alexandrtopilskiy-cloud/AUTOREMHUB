<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class StoreDiagnosticReportRequest extends ApiValidationRequest
{
    public function rules(): array
    {
        return [
            'revision'        => ['required', 'integer'],
            'conclusion'      => ['required', 'string', 'max:10000'],
            'attachmentIds'   => ['sometimes', 'array', 'list', 'max:20'],
            'attachmentIds.*' => ['required', 'uuid'],
        ];
    }
}