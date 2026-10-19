<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\WorkCatalogReferenceException;
use App\Models\Vehicle;
use App\Models\VehicleDriveType;
use App\Models\VehicleEngineVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleTransmissionType;

final class WorkCatalogReferenceResolver
{
    /** @var array<string, object|null> */
    private array $cache = [];

    public function __construct(private readonly int $tenantId)
    {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, int|string|null>
     */
    public function resolve(array $row): array
    {
        $vehicle = $this->vehicle((string) $row['vin']);
        $vehicle?->loadMissing([
            'modelReference.make',
            'generationReference',
            'engineReference',
        ]);
        $make = $this->make((string) $row['make']);
        $lookupMake = $make ?? $vehicle?->modelReference?->make;
        $model = $this->model((string) $row['model'], $lookupMake);
        $lookupModel = $model ?? $vehicle?->modelReference;
        $generation = $this->generation((string) $row['generation'], $lookupModel);
        $engine = $this->engine(
            (string) $row['engine'],
            $lookupModel,
            $generation,
            $vehicle,
        );
        $transmission = $this->transmission((string) $row['transmission']);
        $drive = $this->drive((string) $row['drive']);

        if ($vehicle !== null) {
            $this->assertVehicleAttributes(
                $vehicle,
                $make,
                $model,
                $generation,
                $engine,
                $transmission,
                $drive,
            );
        }

        $hasModification = $engine !== null
            || $transmission !== null
            || $drive !== null
            || trim((string) $row['modificationCode']) !== '';

        if ($hasModification && $generation === null && $vehicle === null) {
            throw new WorkCatalogReferenceException(
                'generation',
                'Для модификации обязательно укажите поколение '
                . 'или конкретный VIN',
            );
        }

        return [
            'vehicle_id' => $vehicle?->id,
            'vehicle_make_id' => $make?->id,
            'vehicle_model_id' => $model?->id,
            'vehicle_generation_id' => $generation?->id,
            'vehicle_engine_variant_id' => $engine?->id,
            'vehicle_transmission_type_id' => $transmission?->id,
            'vehicle_drive_type_id' => $drive?->id,
            'modification_code' => $this->nullable((string) $row['modificationCode']),
        ];
    }

    private function vehicle(string $vin): ?Vehicle
    {
        $vin = strtoupper(trim($vin));
        if ($vin === '') {
            return null;
        }

        if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
            throw new WorkCatalogReferenceException(
                'vin',
                'VIN должен содержать 17 допустимых символов',
            );
        }

        $vehicle = $this->remember(
            'vehicle:' . $vin,
            fn () => Vehicle::query()
                ->where('tenant_id', $this->tenantId)
                ->where('vin', $vin)
                ->first(),
        );

        if ($vehicle === null) {
            throw new WorkCatalogReferenceException(
                'vin',
                'VIN не найден в справочнике ТС Заказчиков',
            );
        }

        return $vehicle;
    }

    private function make(string $name): ?VehicleMake
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $normalized = VehicleReferenceService::normalizeName($name);
        $make = $this->remember(
            'make:' . $normalized,
            fn () => VehicleMake::query()
                ->where('active', true)
                ->where('normalized_name', $normalized)
                ->first(),
        );

        if ($make === null) {
            throw new WorkCatalogReferenceException(
                'make',
                'Марка не найдена во внутреннем справочнике ТС',
            );
        }

        return $make;
    }

    private function model(string $name, ?VehicleMake $make): ?VehicleModel
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if ($make === null) {
            throw new WorkCatalogReferenceException(
                'model',
                'Для модели обязательно укажите марку',
            );
        }

        $normalized = VehicleReferenceService::normalizeName($name);
        $model = $this->remember(
            'model:' . $make->id . ':' . $normalized,
            fn () => VehicleModel::query()
                ->where('active', true)
                ->where('vehicle_make_id', $make->id)
                ->where('normalized_name', $normalized)
                ->first(),
        );

        if ($model === null) {
            throw new WorkCatalogReferenceException(
                'model',
                'Модель не найдена у выбранной марки',
            );
        }

        return $model;
    }

    private function generation(string $name, ?VehicleModel $model): ?VehicleGeneration
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if ($model === null) {
            throw new WorkCatalogReferenceException(
                'generation',
                'Для поколения обязательно укажите модель',
            );
        }

        $normalized = VehicleReferenceService::normalizeName($name);
        $generation = $this->remember(
            'generation:' . $model->id . ':' . $normalized,
            fn () => VehicleGeneration::query()
                ->where('active', true)
                ->whereIn('vehicle_model_id', VehicleReferenceIdentity::modelIds($model))
                ->where('normalized_name', $normalized)
                ->first(),
        );

        if ($generation === null) {
            throw new WorkCatalogReferenceException(
                'generation',
                'Поколение не относится к выбранной модели',
            );
        }

        return $generation;
    }

    private function engine(
        string $code,
        ?VehicleModel $model,
        ?VehicleGeneration $generation,
        ?Vehicle $vehicle,
    ): ?VehicleEngineVariant {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        if ($model === null) {
            throw new WorkCatalogReferenceException(
                'engine',
                'Для двигателя обязательно укажите марку и модель',
            );
        }

        $normalized = mb_strtolower($code);
        $generationKey = $generation?->id ?? 'any';
        $vehicleEngineId = $vehicle?->vehicle_engine_variant_id;
        $engine = $this->remember(
            'engine:' . $model->id . ':' . $generationKey . ':'
            . ($vehicleEngineId ?? 'any') . ':' . $normalized,
            function () use (
                $model,
                $generation,
                $vehicleEngineId,
                $normalized,
            ): ?VehicleEngineVariant {
                $query = VehicleEngineVariant::query()
                    ->selectRaw('DISTINCT ON (code, engine_type, power_hp) vehicle_engine_variants.*')
                    ->orderBy('code')->orderBy('engine_type')->orderBy('power_hp')->orderBy('id')
                    ->where('active', true)
                    ->whereIn('vehicle_model_id', VehicleReferenceIdentity::modelIds($model))
                    ->whereRaw('lower(code) = ?', [$normalized]);

                if ($vehicleEngineId !== null) {
                    return $query->whereKey($vehicleEngineId)->first();
                }

                if ($generation !== null) {
                    $specific = (clone $query)
                        ->whereIn('vehicle_generation_id', VehicleReferenceIdentity::generationIds($generation))
                        ->limit(2)
                        ->get();
                    $candidates = $specific->isNotEmpty()
                        ? $specific
                        : $query->whereNull('vehicle_generation_id')->limit(2)->get();
                } else {
                    $candidates = $query->limit(2)->get();
                }

                if ($candidates->count() > 1) {
                    throw new WorkCatalogReferenceException(
                        'engine',
                        'Индекс двигателя найден в нескольких вариантах. '
                        . 'Уточните поколение или конкретный VIN',
                    );
                }

                return $candidates->first();
            },
        );

        if ($engine === null) {
            throw new WorkCatalogReferenceException(
                'engine',
                'Двигатель не относится к выбранной модели',
            );
        }

        if ($generation !== null && $engine->vehicle_generation_id !== null
            && !VehicleReferenceIdentity::sameGeneration($engine->generation, $generation)) {
            throw new WorkCatalogReferenceException(
                'engine',
                'Двигатель не относится к выбранному поколению',
            );
        }

        return $engine;
    }

    private function transmission(string $value): ?VehicleTransmissionType
    {
        return $this->simpleReference(
            $value,
            'transmission',
            VehicleTransmissionType::class,
            'transmission',
            'Трансмиссия не найдена в закрытом справочнике',
        );
    }

    private function drive(string $value): ?VehicleDriveType
    {
        return $this->simpleReference(
            $value,
            'drive',
            VehicleDriveType::class,
            'drive',
            'Привод не найден в закрытом справочнике',
        );
    }

    /** @param class-string $class */
    private function simpleReference(
        string $value,
        string $kind,
        string $class,
        string $field,
        string $message,
    ): ?object {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = mb_strtolower($value);
        $record = $this->remember(
            $kind . ':' . $normalized,
            fn () => $class::query()
                ->where('active', true)
                ->where(function ($query) use ($normalized): void {
                    $query->whereRaw('lower(code) = ?', [$normalized])
                        ->orWhereRaw('lower(name) = ?', [$normalized]);
                })
                ->first(),
        );

        if ($record === null) {
            throw new WorkCatalogReferenceException($field, $message);
        }

        return $record;
    }

    private function assertVehicleAttributes(
        Vehicle $vehicle,
        ?VehicleMake $make,
        ?VehicleModel $model,
        ?VehicleGeneration $generation,
        ?VehicleEngineVariant $engine,
        ?VehicleTransmissionType $transmission,
        ?VehicleDriveType $drive,
    ): void {
        $vehicle->loadMissing('modelReference');

        $mismatch = ($model !== null && !VehicleReferenceIdentity::sameModel($vehicle->modelReference, $model))
            || ($make !== null && $vehicle->modelReference?->vehicle_make_id !== $make->id)
            || ($generation !== null && !VehicleReferenceIdentity::sameGeneration($vehicle->generationReference, $generation))
            || ($engine !== null && !VehicleReferenceIdentity::sameEngine($vehicle->engineReference, $engine))
            || ($transmission !== null
                && $vehicle->vehicle_transmission_type_id !== $transmission->id)
            || ($drive !== null && $vehicle->vehicle_drive_type_id !== $drive->id);

        if ($mismatch) {
            throw new WorkCatalogReferenceException(
                'vin',
                'Признаки применимости не соответствуют указанному VIN',
            );
        }
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function remember(string $key, callable $loader): ?object
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $loader();
        }

        return $this->cache[$key];
    }
}
