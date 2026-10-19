<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TimeNorm;

final class TimeNormDescription
{
    public const RELATIONS = ['vehicleMake', 'vehicleModel.make', 'vehicleGeneration.model.make',
        'vehicleEngineVariant.model.make', 'vehicleTransmissionType', 'vehicleDriveType', 'vehicle'];

    public static function label(?TimeNorm $norm): string
    {
        if ($norm === null) {
            return 'Применимость не определена';
        }
        $norm->loadMissing(self::RELATIONS);
        $parts = [];
        $model = $norm->vehicleModel ?? $norm->vehicleGeneration?->model ?? $norm->vehicleEngineVariant?->model;
        $make = $norm->vehicleMake ?? $model?->make;
        if ($make !== null) {
            $parts[] = $make->name;
        }
        if ($model !== null) {
            $parts[] = $model->name;
        }
        if ($norm->vehicleGeneration !== null) {
            $parts[] = $norm->vehicleGeneration->name ?: $norm->vehicleGeneration->body_code;
        }
        if ($norm->vehicleEngineVariant !== null) {
            $parts[] = $norm->vehicleEngineVariant->code;
        }
        if ($norm->vehicleTransmissionType !== null) {
            $parts[] = $norm->vehicleTransmissionType->name;
        }
        if ($norm->vehicleDriveType !== null) {
            $parts[] = $norm->vehicleDriveType->name;
        }
        if ($norm->modification_code) {
            $parts[] = $norm->modification_code;
        }
        if ($norm->vehicle !== null) {
            if ($parts === []) {
                $parts[] = trim($norm->vehicle->make . ' ' . $norm->vehicle->model);
            }
            $parts[] = 'VIN ' . $norm->vehicle->vin;
        }
        $parts = array_values(array_filter($parts, static fn ($value) => trim((string) $value) !== ''));
        return $parts === [] ? 'Применимость общая' : 'Применимость к ' . implode(' · ', $parts);
    }
}
