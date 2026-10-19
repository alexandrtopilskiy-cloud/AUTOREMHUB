<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string|null $hours
 * @property string $norm_id
 * @property bool $active
 * @property CarbonImmutable|null $archived_at
 * @property int|null $applicability_id
 * @property int|null $vehicle_make_id
 * @property int|null $vehicle_model_id
 */
final class TimeNorm extends Entity
{
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $model): void {
            $workType = WorkType::query()->find($model->work_type_id);
            $model->tenant_id = $model->tenant_id ?: $workType?->tenant_id;
            $model->norm_id = $model->norm_id ?: 'MN-' . strtoupper(substr(
                str_replace('-', '', (string) Str::uuid()),
                0,
                16,
            ));
            $model->active = $model->active ?? true;
            $model->catalog_version = $model->catalog_version
                ?: ($workType?->catalog_version ?: 'manual');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'decimal:3',
            'active' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function applicability(): BelongsTo
    {
        return $this->belongsTo(TimeNormApplicability::class, 'applicability_id');
    }

    public function vehicleMake(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vehicleGeneration(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class);
    }

    public function vehicleEngineVariant(): BelongsTo
    {
        return $this->belongsTo(VehicleEngineVariant::class);
    }

    public function vehicleTransmissionType(): BelongsTo
    {
        return $this->belongsTo(VehicleTransmissionType::class);
    }

    public function vehicleDriveType(): BelongsTo
    {
        return $this->belongsTo(VehicleDriveType::class);
    }

    public function catalogImport(): BelongsTo
    {
        return $this->belongsTo(WorkCatalogImport::class, 'catalog_import_id');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }
}
