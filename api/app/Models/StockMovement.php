<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка регистра движений ЗЧ: quantity > 0 — приход, < 0 — расход.
 *
 * @property int $organization_id
 * @property int $warehouse_id
 * @property int $catalog_part_id
 * @property string $quantity
 * @property string $unit_cost
 * @property string $source_type
 * @property int $source_id
 */
final class StockMovement extends Model
{
    public const SOURCE_RECEIPT = 'receipt';
    public const SOURCE_SALE = 'sale';
    public const SOURCE_REQUEST = 'repair_request';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
