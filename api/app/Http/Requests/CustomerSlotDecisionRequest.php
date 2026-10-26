<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApprovalDecision;

final class CustomerSlotDecisionRequest extends ApiValidationRequest
{
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer'],
            'decision' => [
                'required',
                'string',
                'in:' . implode(',', [
                    ApprovalDecision::Accepted->value,
                    ApprovalDecision::Rescheduled->value,
                ]),
            ],
            'slot'     => ['sometimes', 'string'],
            'comment'  => ['sometimes', 'string', 'max:10000'],
        ];
    }
}
