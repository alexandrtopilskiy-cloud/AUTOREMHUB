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
 * @property int|null $vehicle_id
 * @property int|null $vehicle_generation_id
 * @property int|null $vehicle_engine_variant_id
 * @property int|null $vehicle_transmission_type_id
 * @property int|null $vehicle_drive_type_id
 * @property string|null $modification_code
 * @property string $vehicle_model
 * @property string $source
 * @property string $catalog_version
 * @property int $tenant_id
 * @property int $work_type_id
 * @property int|null $catalog_import_id
 *
 * @property-read TimeNormApplicability|null $applicability
 * @property-read VehicleMake|null $vehicleMake
 * @property-read VehicleModel|null $vehicleModel
 * @property-read Vehicle|null $vehicle
 * @property-read VehicleGeneration|null $vehicleGeneration
 * @property-read VehicleEngineVariant|null $vehicleEngineVariant
 * @property-read VehicleTransmissionType|null $vehicleTransmissionType
 * @property-read VehicleDriveType|null $vehicleDriveType
 * @property-read WorkType|null $workType
 * @property-read WorkCatalogImport|null $catalogImport
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

    /** @return BelongsTo<TimeNormApplicability, $this> */
    public function applicability(): BelongsTo
    {
        return $this->belongsTo(TimeNormApplicability::class, 'applicability_id');
    }

    /** @return BelongsTo<VehicleMake, $this> */
    public function vehicleMake(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<VehicleGeneration, $this> */
    public function vehicleGeneration(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class);
    }

    /** @return BelongsTo<VehicleEngineVariant, $this> */
    public function vehicleEngineVariant(): BelongsTo
    {
        return $this->belongsTo(VehicleEngineVariant::class);
    }

    /** @return BelongsTo<VehicleTransmissionType, $this> */
    public function vehicleTransmissionType(): BelongsTo
    {
        return $this->belongsTo(VehicleTransmissionType::class);
    }

    /** @return BelongsTo<VehicleDriveType, $this> */
    public function vehicleDriveType(): BelongsTo
    {
        return $this->belongsTo(VehicleDriveType::class);
    }

    /** @return BelongsTo<WorkCatalogImport, $this> */
    public function catalogImport(): BelongsTo
    {
        return $this->belongsTo(WorkCatalogImport::class, 'catalog_import_id');
    }

    /** @return BelongsTo<WorkType, $this> */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }
}
