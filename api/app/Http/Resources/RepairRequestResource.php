<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\RepairRequestCalculator;
use App\Support\ApiPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RepairRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        /** @var ApiPresenter $presenter */
        $presenter = app(ApiPresenter::class);
        $heavyData = $presenter->document($this->resource, $user);

        return array_merge([
            'id'              => $this->uuid,
            'systemId'        => $this->id,
            'number'          => $this->number,
            'stage'           => $this->stage->value,
            'statusLabel'     => $this->stage->label(),
            'revision'        => $this->revision,
            'estimateVersion' => $this->estimate_version,
            'createdAt'       => $this->created_at?->toISOString(),
            'updatedAt'       => $this->updated_at?->toISOString(),
            'total'           => (float) app(RepairRequestCalculator::class)->total($this->resource),
        ], $heavyData);
    }
}
