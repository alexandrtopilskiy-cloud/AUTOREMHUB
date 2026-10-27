<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RequestPart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestPart */
final class PartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->uuid,
            'systemId'        => $this->id,
            'name'            => $this->name,
            'article'         => $this->article,
            'manufacturer'    => $this->manufacturer_snapshot ?? $this->catalogPart?->manufacturer ?? '',
            'purchasePrice'   => $this->purchase_price === null ? null : (float) $this->purchase_price,
            'catalogPartId'   => $this->catalog_part_id !== null ? ($this->catalogPart?->uuid ?? '') : '',
            'unit'            => $this->unit,
            'supplierId'      => $this->supplier?->uuid ?? '', // если есть связь
            'supplierName'    => $this->supplier?->name ?? '',
            'qty'             => (float) $this->quantity,
            'price'           => (float) $this->price,
            'returnedQty'     => (float) $this->returned_quantity,
            'status'          => $this->status->value,
            'approvedVersion' => $this->approved_version,
        ];
    }
}
