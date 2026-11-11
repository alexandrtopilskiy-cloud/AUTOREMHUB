<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Складской документ: Приход (receipt, PRH-), Реализация (sale, RLZ-) или
 * внутреннее Перемещение ЗЧ со склада УК на «Склад от УК» СТО (transfer, PRM-).
 *
 * @property int $tenant_id
 * @property int $organization_id
 * @property string $type
 * @property int $sequence
 * @property string $number
 * @property string $status
 * @property \Carbon\CarbonImmutable $document_date
 * @property int $warehouse_id
 * @property int|null $supplier_id
 * @property int|null $customer_organization_id
 * @property int|null $vehicle_id
 * @property int $revision
 */
final class StockDocument extends Entity
{
    public const RECEIPT = 'receipt';
    public const SALE = 'sale';
    public const TRANSFER = 'transfer';
    public const CREATED = 'created';
    public const POSTED = 'posted';

    protected function casts(): array
    {
        return [
            'document_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'sequence' => 'integer',
            'revision' => 'integer',
        ];
    }

    public static function prefix(string $type): string
    {
        return match ($type) {
            self::RECEIPT => 'PRH-',
            self::TRANSFER => 'PRM-',
            default => 'RLZ-',
        };
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    /** @return HasMany<StockDocumentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockDocumentItem::class)->orderBy('line_no');
    }

    /** @return HasMany<StockDocumentPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(StockDocumentPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Organization, $this> Организация-владелец склада (УК или СТО). */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
