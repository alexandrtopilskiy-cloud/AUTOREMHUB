<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DomainInput as Input;
use Illuminate\Foundation\Http\FormRequest;

final class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'revision'     => ['required', 'integer'],
            'number'       => ['required', 'string', 'max:100'],
            'amount'       => ['required', 'numeric', 'min:0', 'max:1000000000000', Input::numeric(2)],
            'attachmentId' => ['required', 'uuid'],
        ];
    }
}