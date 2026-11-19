<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array<int, mixed> $resource
 */
final class VehicleTransmissionsResource extends JsonResource
{
    public static $wrap = null;
    
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transmissions' => $this->resource,
        ];
    }
}
