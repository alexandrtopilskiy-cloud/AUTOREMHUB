<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class RepairRequestCollection extends ResourceCollection
{
    public static $wrap = 'requests';

    /**
     * Имя ключа для массива данных.
     */
    public $collects = RepairRequestResource::class;

    public function toArray(Request $request): array
    {
        return [
            'requests' => $this->collection,
        ];
    }
}
