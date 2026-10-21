<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Edo\EdoDocumentType;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property array<string, mixed> $data
 * @property array<int, mixed> $works
 * @property array<int, mixed> $history
 * @property int $revision
 * @property RepairStage $stage
 * @property int|null $previous_service_mileage
 * @property CarbonImmutable|null $previous_service_at
 * @property array<string, string>|null $contact_snapshot
 * @property array<string, string>|null $driver_snapshot
 * @property int|null $created_by
 * @property int $estimate_version
 * @property string $review_context
 * @property int $review_baseline_version
 * @property array<string, mixed>|null $review_baseline_snapshot
 * @property CarbonImmutable|null $planned_completion_at
 * @property CarbonImmutable|null $repair_completed_at
 *
 * @property-read Vehicle|null $vehicleRecord
 * @property-read VehicleContact|null $contactRecord
 * @property-read VehicleContact|null $driverRecord
 * @property-read User|null $creator
 * @property-read Station|null $stationRecord
 * @property-read ServiceReason|null $reasonRecord
 * @property-read ServiceReason|null $reasonSubtype
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RequestWork> $workLines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RequestPart> $parts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RequestEvent> $events
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RequestStatusPeriod> $periods
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Attachment> $attachments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DiagnosticReport> $diagnoses
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RequestApproval> $approvals
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EdoDocument> $edoDocuments
 * @property-read WorkOrder|null $workOrder
 * @property-read Invoice|null $invoice
 * @property-read VehicleAcceptanceAct|null $acceptanceAct
 * @property-read Organization $customerOrganization
 */
final class RepairRequest extends Entity
{
    use HasFactory;
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data'                     => 'array',
            'works'                    => 'array',
            'history'                  => 'array',
            'revision'                 => 'integer',
            'stage'                    => RepairStage::class,
            'previous_service_mileage' => 'integer',
            'previous_service_at'      => 'immutable_datetime',
            'contact_snapshot'         => 'array',
            'driver_snapshot'          => 'array',
            'estimate_version'         => 'integer',
            'review_baseline_version'  => 'integer',
            'review_baseline_snapshot' => 'array',
            'planned_completion_at'    => 'immutable_datetime',
            'repair_completed_at'      => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicleRecord(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** @return BelongsTo<VehicleContact, $this> */
    public function contactRecord(): BelongsTo
    {
        return $this->belongsTo(VehicleContact::class, 'contact_id');
    }

    /** @return BelongsTo<VehicleContact, $this> */
    public function driverRecord(): BelongsTo
    {
        return $this->belongsTo(VehicleContact::class, 'driver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Station, $this> */
    public function stationRecord(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'assigned_station_id');
    }

    /** @return BelongsTo<ServiceReason, $this> */
    public function reasonRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceReason::class, 'reason_id');
    }

    /** @return BelongsTo<ServiceReason, $this> */
    public function reasonSubtype(): BelongsTo
    {
        return $this->belongsTo(ServiceReason::class, 'reason_subtype_id');
    }

    /** @return HasMany<RequestWork, $this> */
    public function workLines(): HasMany
    {
        return $this->hasMany(RequestWork::class);
    }

    /** @return HasMany<RequestPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(RequestPart::class);
    }

    /** @return HasMany<RequestEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RequestEvent::class)->orderBy('id');
    }

    /** @return HasMany<RequestStatusPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(RequestStatusPeriod::class)->orderBy('id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasOne<RequestChat, $this> */
    public function chat(): HasOne
    {
        return $this->hasOne(RequestChat::class);
    }

    /** @return HasMany<DiagnosticReport, $this> */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(DiagnosticReport::class)->orderBy('id');
    }

    /** @return HasMany<RequestApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(RequestApproval::class)->orderBy('id');
    }

    /** @return HasOne<WorkOrder, $this> */
    public function workOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class);
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /** @return HasOne<VehicleAcceptanceAct, $this> */
    public function acceptanceAct(): HasOne
    {
        return $this->hasOne(VehicleAcceptanceAct::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id');
    }

    public static function forUser(User $user): Builder
    {
        $query = self::query()->where('tenant_id', $user->tenantId());

        if ($user->role === UserRole::Customer) {
            $query
                ->where('customer_organization_id', $user->organization_id)
                ->where(function (Builder $q) use ($user): void {
                    $q
                        ->where('stage', '>', RepairStage::Draft->value)
                        ->orWhere('created_by', $user->id);
                });
        }

        if ($user->role === UserRole::Station) {
            $query->where(function (Builder $stationScope) use ($user): void {
                $stationScope->where('created_by', $user->id)->orWhereIn(
                    'assigned_station_id',
                    Station::query()
                    ->select('id')
                    ->where('organization_id', $user->organization_id),
                );
            });
        }

        if ($user->role === UserRole::Manager) {
            $query->where(function (Builder $drafts) use ($user): void {
                $drafts->where('stage', '>', RepairStage::Draft->value)
                    ->orWhere('created_by', $user->id);
            });
        }

        return $query;
    }

    public static function resolveFor(User $user, string $key, bool $lock = false): self
    {
        $query = self::forUser($user);

        if (Str::isUuid($key)) {
            $query->where('uuid', $key);
        } else {
            $query->where('number', $key);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * Чат открыт всем пользователям организаций — участников заявки,
     * независимо от того, кто сейчас редактирует документ.
     * В отличие от forUser(), черновик виден коллегам по организации-заказчику.
     */
    public static function forChatUser(User $user): Builder
    {
        $query = self::query()->where('tenant_id', $user->tenantId());

        if ($user->role === UserRole::Customer) {
            $query->where('customer_organization_id', $user->organization_id);
        }

        if ($user->role === UserRole::Station) {
            $query->whereIn(
                'assigned_station_id',
                Station::query()
                    ->select('id')
                    ->where('organization_id', $user->organization_id),
            );
        }

        if ($user->role === UserRole::Manager) {
            $query->where(function (Builder $drafts) use ($user): void {
                $drafts
                    ->where('stage', '>', RepairStage::Draft->value)
                    ->orWhere('created_by', $user->id);
            });
        }

        return $query;
    }

    public static function resolveChatFor(User $user, string $key, bool $lock = false): self
    {
        $query = self::forChatUser($user);

        $query->where(fn (Builder $q) => Str::isUuid($key)
            ? $q->where('uuid', $key)
            : $q->where('number', $key));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function edoDocuments(): HasMany
    {
        return $this->hasMany(EdoDocument::class, 'source_id')
            ->where('source_type', self::class);
    }

    public function edoDocument(EdoDocumentType $type): ?EdoDocument
    {
        return $this->edoDocuments()
            ->where('document_type', $type->value)
            ->latest('id')
            ->first();
    }
}
