<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DomainInput as Input;
use App\Services\Workflow;

final class UpdateRepairRequestRequest extends ApiValidationRequest
{
    public function rules(): array
    {
        $rules = [];

        // Правила для текстовых полей (из Workflow::TEXT_FIELDS)
        foreach (Workflow::TEXT_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'string', 'max:10000'];
        }

        // Правила для UUID полей
        $uuidFields = [
            'vehicleId',
            'contactId',
            'driverId',
            'stationId',
            'reasonId',
            'reasonSubtypeId',
        ];
        foreach ($uuidFields as $field) {
            $rules[$field] = Input::uuidOrEmpty();
        }

        // Специфичные правила
        $rules['laborRateType'] = ['sometimes', 'in:general,official,other'];
        $rules['works'] = ['sometimes', 'array', 'list', 'max:200'];
        $rules['parts'] = ['sometimes', 'array', 'list', 'max:200'];
        $rules['revision'] = ['required', 'integer']; // Обязательно для optimistic locking

        return $rules;
    }
}