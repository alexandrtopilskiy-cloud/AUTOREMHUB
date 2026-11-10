<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Оплата по документу склада: Приход — Поставщику, Реализация — от Заказчика.
 *
 * @property int $stock_document_id
 * @property \Carbon\CarbonImmutable $paid_on
 * @property string $amount
 * @property string $method
 * @property string|null $comment
 * @property int|null $created_by
 */
final class StockDocumentPayment extends Entity
{
    public const METHODS = [
        'bank' => 'Безналичный расчёт',
        'cash' => 'Наличные',
        'card' => 'Банковская карта',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<StockDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
