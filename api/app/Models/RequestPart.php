<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RequestPartStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quantity
 * @property string $price
 * @property string $returned_quantity
 * @property string $unit
 * @property int|null $catalog_part_id
 * @property int|null $approved_version
 * @property RequestPartStatus $status
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

    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(CatalogPart::class);
    }
}
