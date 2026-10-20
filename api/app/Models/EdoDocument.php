<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property EdoDocumentType   $document_type
 * @property EdoDocumentStatus $status
 * @property EdoProviderEnum   $provider
 * @property string            $number
 * @property CarbonImmutable   $document_date
 * @property string|null       $external_id
 * @property string|null       $external_uuid
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $signed_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $last_synced_at
 *
 * @property-read Tenant $tenant
 * @property-read Organization $organization
 * @property-read Organization|null $counterparty
 * @property-read RepairRequest|WorkOrder|Invoice|null $source
 * @property-read Attachment|null $payloadAttachment
 * @property-read Attachment|null $signedAttachment
 * @property-read Attachment|null $acceptanceAttachment
 */
final class EdoDocument extends Entity
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type'  => EdoDocumentType::class,
            'status'         => EdoDocumentStatus::class,
            'provider'       => EdoProviderEnum::class,
            'document_date'  => 'immutable_date',
            'meta'           => 'array',
            'sent_at'        => 'immutable_datetime',
            'delivered_at'   => 'immutable_datetime',
            'signed_at'      => 'immutable_datetime',
            'accepted_at'    => 'immutable_datetime',
            'rejected_at'    => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'payload_attachment_id' => 'integer',
            'signed_attachment_id'  => 'integer',
            'acceptance_attachment_id' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'counterparty_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function payloadAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'payload_attachment_id');
    }

    public function signedAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signed_attachment_id');
    }

    public function acceptanceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'acceptance_attachment_id');
    }

    /* ─── Scopes ─── */

    public static function forTenant(Tenant|int $tenant): Builder
    {
        return self::query()->where('tenant_id', $tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    public static function pending(): Builder
    {
        return self::query()->whereIn('status', [
            EdoDocumentStatus::Draft->value,
            EdoDocumentStatus::ToSign->value,
            EdoDocumentStatus::Prepared->value,
            EdoDocumentStatus::Sended->value,
            EdoDocumentStatus::Delivered->value,
            EdoDocumentStatus::OnAgreement->value,
        ]);
    }

    public static function finished(): Builder
    {
        return self::query()->whereIn('status', [
            EdoDocumentStatus::Signed->value,
            EdoDocumentStatus::Accepted->value,
            EdoDocumentStatus::Rejected->value,
            EdoDocumentStatus::Revoked->value,
            EdoDocumentStatus::AcceptedAvoidance->value,
        ]);
    }

    /**
     * Документы, у которых не было синка за последние N минут —
     * используются в edo:sync-status.
     */
    public static function stale(int $minutes = 15): Builder
    {
        return self::pending()
        ->where(function (Builder $q) use ($minutes): void {
            $q->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', now()->subMinutes($minutes));
        });
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            EdoDocumentStatus::Signed,
            EdoDocumentStatus::Accepted,
            EdoDocumentStatus::Rejected,
            EdoDocumentStatus::Revoked,
            EdoDocumentStatus::AcceptedAvoidance,
        ], true);
    }

    public function hasExternalId(): bool
    {
        return $this->external_id !== null && $this->external_id !== '';
    }

    public function isLinkedTo(RepairRequest $request): bool
    {
        return $this->source_type === RepairRequest::class
        && (int) $this->source_id === (int) $request->id;
    }

    public function isUpd(): bool
    {
        return $this->document_type === EdoDocumentType::UpdSf;
    }

    public function isDraft(): bool
    {
        return $this->status === EdoDocumentStatus::Draft;
    }

    /**
     * Документ нельзя корректировать: он отклонён, отозван или аннулирован.
     * Не путать с isFinished() — тот шире (включает успешное завершение).
     */
    public function isFinalized(): bool
    {
        return in_array($this->status, [
            EdoDocumentStatus::Rejected,
            EdoDocumentStatus::Revoked,
            EdoDocumentStatus::AcceptedAvoidance,
        ], true);
    }

    public function hasItemsSnapshot(): bool
    {
        $snapshot = $this->meta['items_snapshot'] ?? null;

        return is_array($snapshot) && $snapshot !== [];
    }

    public function itemsSnapshot(): array
    {
        return $this->hasItemsSnapshot() ? $this->meta['items_snapshot'] : [];
    }

    public static function resolveForUser(User $user, string $uuid): self
    {
        return self::query()
        ->where('uuid', $uuid)
            ->where('tenant_id', $user->tenantId())
            ->firstOrFail();
    }
}