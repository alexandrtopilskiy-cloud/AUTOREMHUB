<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use Carbon\CarbonImmutable;

/**
 * @property string $amount
 * @property string|null $paid_amount
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $verified_at
 * @property InvoiceStatus $status
 */
final class Invoice extends Entity
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'paid_amount'   => 'decimal:2',
            'paid_at'       => 'immutable_datetime',
            'verified_at'   => 'immutable_datetime',
            'status'        => InvoiceStatus::class,
        ];
    }
}
