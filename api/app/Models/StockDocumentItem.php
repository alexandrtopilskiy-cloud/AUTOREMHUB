<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $stock_document_id
 * @property int $line_no
 * @property int $catalog_part_id
 * @property string $quantity
 * @property string|null $purchase_price
 * @property string|null $sale_price
 * @property int|null $manager_id
 */
final class StockDocumentItem extends Entity
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'line_no' => 'integer',
        ];
    }

    /** @return BelongsTo<CatalogPart, $this> */
    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(CatalogPart::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
