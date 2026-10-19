<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AttachmentPurpose;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAttachmentRequest extends FormRequest
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
            'file'     => ['required', 'file', 'max:102400'],
            'revision' => ['required', 'regex:/^\d+$/'],
            'purpose'  => [
                'required',
                'in:' . implode(',', AttachmentPurpose::values()),
            ],
        ];
    }
}
