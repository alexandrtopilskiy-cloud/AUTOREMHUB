<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RequestDocumentType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Документ закрытия ЗР (акт передачи ТС, ЗН, УПД, счёт, отчёт агента).
 *
 * @property RequestDocumentType $type
 * @property string $number
 * @property array<string, mixed> $payload
 */
final class RequestDocument extends Entity
{
    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'                      => RequestDocumentType::class,
            'document_date'             => 'immutable_date',
            'amount'                    => 'decimal:2',
            'vat_amount'                => 'decimal:2',
            'payload'                   => 'array',
            'driver_documents_verified' => 'boolean',
            'signed_at'                 => 'immutable_datetime',
            'paid_amount'               => 'decimal:2',
            'paid_at'                   => 'immutable_date',
            'paid_marked_at'            => 'immutable_datetime',
        ];
    }

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function signedAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signed_attachment_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'seller_organization_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
