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
                'in:' . implode(',', array_map(
                    static fn (AttachmentPurpose $purpose): string => $purpose->value,
                    array_filter(AttachmentPurpose::cases(), static fn (AttachmentPurpose $purpose): bool => !$purpose->isSystem()),
                )),
            ],
        ];
    }
}
