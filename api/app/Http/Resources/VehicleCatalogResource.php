<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array<string, mixed> $resource
 */
final class VehicleCatalogResource extends JsonResource
{
    public static $wrap = null;
    
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Если сервис возвращает объект LengthAwarePaginator, JsonResource 
        // автоматически обернет его в data и добавит метаданные (links, meta)
        return $this->resource;
    }
}
