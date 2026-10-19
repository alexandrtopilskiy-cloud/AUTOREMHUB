<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PartMovementKind;
use App\Support\DomainInput as Input;
use Illuminate\Foundation\Http\FormRequest;

final class MovePartRequest extends FormRequest
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
            'revision'            => ['required', 'integer'],
            'kind'                => [
                'required',
                'in:' . implode(',', PartMovementKind::values()),
            ],
            'qty'                 => ['required', 'numeric', 'min:0.001', 'max:1000000', Input::numeric(3)],
            'comment'             => ['required', 'string', 'max:10000'],
            'replacementPartId'   => Input::uuidOrEmpty(),
            'attachmentIds'       => ['present', 'array', 'list'],
            'attachmentIds.*'     => ['uuid'],
        ];
    }
}
