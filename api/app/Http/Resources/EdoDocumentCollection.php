<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class EdoDocumentCollection extends ResourceCollection
{
    public $collects = EdoDocumentResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'total'        => $this->total(),
                'perPage'      => $this->perPage(),
                'currentPage'  => $this->currentPage(),
                'lastPage'     => $this->lastPage(),
            ],
        ];
    }
}
