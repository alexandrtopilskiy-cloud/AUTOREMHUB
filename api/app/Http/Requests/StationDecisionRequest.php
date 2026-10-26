<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class StationDecisionRequest extends ApiValidationRequest
{
    public function rules(): array
    {
        return [
            'revision'    => ['required', 'integer'],
            'decision'    => ['required', 'string', 'in:confirmed,rescheduled,declined'],
            'comment'     => ['sometimes', 'nullable', 'string', 'max:10000'],
            'stationSlot' => ['sometimes', 'string'],
        ];
    }
}
