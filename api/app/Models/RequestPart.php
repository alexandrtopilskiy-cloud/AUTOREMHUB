<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RequestPartStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $catalog_part_id
 * @property int|null $supplier_id
 * @property string $name
 * @property string $article
 * @property string $unit
 * @property string $quantity
 * @property string $price
 * @property string $manufacturer_snapshot
 * @property string|null $purchase_price
 * @property \App\Enums\RequestPartStatus $status
 * @property int|null $approved_version
 * @property string $returned_quantity
 *
 * @property-read CatalogPart|null $catalogPart
 * @property-read Supplier|null $supplier
 */
final class RequestPart extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity'          => 'decimal:3',
            'purchase_price' => 'decimal:2',
            'price'             => 'decimal:2',
            'returned_quantity' => 'decimal:3',
            'approved_version'  => 'integer',
            'status'            => RequestPartStatus::class,
        ];
    }

    /** @return BelongsTo<CatalogPart, $this> */
    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(CatalogPart::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
