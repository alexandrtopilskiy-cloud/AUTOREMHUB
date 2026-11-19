<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class CatalogVehicleReferenceRequest extends ApiValidationRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page'               => ['sometimes', 'integer', 'min:1'],
            'perPage'            => ['sometimes', 'integer', 'between:25,200'],
            'make'               => ['sometimes', 'string', 'max:255'],
            'model'              => ['sometimes', 'string', 'max:255'],
            'modelYearFrom'      => ['sometimes', 'string', 'max:20'],
            'modelYearTo'        => ['sometimes', 'string', 'max:20'],
            'engineIndex'        => ['sometimes', 'string', 'max:100'],
            'bodyCode'           => ['sometimes', 'string', 'max:150'],
            'horsepower'         => ['sometimes', 'string', 'max:20'],
            'engineVolumeLiters' => ['sometimes', 'string', 'max:20'],
            'transmission'       => ['sometimes', 'string', 'max:100'],
            'driveType'          => ['sometimes', 'string', 'max:100'],
            'engineType'         => ['sometimes', 'string', 'max:100'],
        ];
    }
}
