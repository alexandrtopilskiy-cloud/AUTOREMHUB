<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DomainInput as Input;
use Illuminate\Foundation\Http\FormRequest;

final class SubmitInvoicePaymentRequest extends FormRequest
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
            'paidAmount'   => ['required', 'numeric', 'min:0', 'max:1000000000000', Input::numeric(2)],
            'paidAt'       => ['required', 'date'],
            'attachmentId' => ['required', 'uuid'],
            'comment'      => ['required', 'string', 'max:10000'],
        ];
    }
}