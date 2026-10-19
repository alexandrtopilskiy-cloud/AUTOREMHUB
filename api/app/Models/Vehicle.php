<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $tenant_id
 * @property int $organization_id
 * @property string $make
 * @property string $model
 * @property int|null $vehicle_model_id
 * @property int|null $vehicle_generation_id
 * @property int|null $vehicle_engine_variant_id
 * @property int|null $vehicle_transmission_type_id
 * @property int|null $vehicle_drive_type_id
 * @property string|null $modification_code
 * @property string $vin
 * @property string $plate_normalized
 * @property int|null $year
 * @property int|null $baseline_service_mileage
 * @property CarbonImmutable|null $baseline_service_at
 * @property string $labor_rate_coefficient
 * @property string|null $labor_hour_rate
 * @property bool $active
 *
 * @property-read Organization $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VehicleContact> $contacts
 */
final class Vehicle extends Entity
{
    /**
     * Соответствие латинских букв, визуально совпадающих с кириллицей.
     * Используется при нормализации госномеров, чтобы A и А, B и В и т.д.
     * считались одинаковыми.
     */
    private const PLATE_HOMOGLYPHS = [
        'A' => 'А',
        'B' => 'В',
        'E' => 'Е',
        'K' => 'К',
        'M' => 'М',
        'H' => 'Н',
        'O' => 'О',
        'P' => 'Р',
        'C' => 'С',
        'T' => 'Т',
        'Y' => 'У',
        'X' => 'Х',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year'                     => 'integer',
            'baseline_service_mileage' => 'integer',
            'baseline_service_at'      => 'immutable_datetime',
            'labor_rate_coefficient'   => 'decimal:3',
            'labor_hour_rate'          => 'decimal:2',
            'labor_hour_rate_official' => 'decimal:2',
            'labor_hour_rate_other' => 'decimal:2',
            'active'                   => 'boolean',
        ];
    }

    public function laborRate(string $type = 'general'): ?string
    {
        return match ($type) {
            'official' => $this->labor_hour_rate_official,
            'other' => $this->labor_hour_rate_other,
            default => $this->labor_hour_rate,
        };
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function modelReference(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function generationReference(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'vehicle_generation_id');
    }

    public function engineReference(): BelongsTo
    {
        return $this->belongsTo(VehicleEngineVariant::class, 'vehicle_engine_variant_id');
    }

    public function transmissionReference(): BelongsTo
    {
        return $this->belongsTo(
            VehicleTransmissionType::class,
            'vehicle_transmission_type_id',
        );
    }

    public function driveReference(): BelongsTo
    {
        return $this->belongsTo(VehicleDriveType::class, 'vehicle_drive_type_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(VehicleContact::class);
    }

    public static function forUser(User $user): Builder
    {
        $query = self::query()
            ->where('tenant_id', $user->tenantId())
            ->where('active', true);

        if ($user->role === UserRole::Customer) {
            $query->where('organization_id', $user->organization_id);
        }

        if ($user->role === UserRole::Station) {
            $query->whereIn(
                'id',
                RepairRequest::forUser($user)
                    ->select('vehicle_id')
                    ->whereNotNull('vehicle_id')
            );
        }

        return $query;
    }

    /**
     * Нормализует госномер: приводит к верхнему регистру,
     * заменяет латинские гомоглифы на кириллицу и удаляет всё, кроме букв и цифр.
     */
    public static function plateKey(string $plate): string
    {
        $upper = mb_strtoupper($plate);
        $cyrillic = strtr($upper, self::PLATE_HOMOGLYPHS);

        return (string)preg_replace('/[^\p{L}\p{N}]/u', '', $cyrillic);
    }
}
