<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DomainInput as Input;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePartRequest extends FormRequest
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
            'revision'   => ['required', 'integer'],
            'catalogPartId' => Input::uuidOrEmpty(),
            'name'       => ['required', 'string', 'max:500'],
            'article'    => ['present', 'string', 'max:100'],
            'unit'       => ['required', 'string', Rule::in(config('sto.part_units'))],
            'supplierId' => Input::uuidOrEmpty(),
            'qty'        => ['required', 'numeric', 'min:0.001', 'max:1000000', Input::numeric(3)],
            'price'      => ['required', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
        ];
    }
}
