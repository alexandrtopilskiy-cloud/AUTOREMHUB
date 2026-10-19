<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TimeNorm;
use App\Models\Vehicle;
use App\Models\WorkType;
use Illuminate\Support\Collection;

final class TimeNormSelectionService
{
    public const STATUS_SELECTED = 'selected';

    public const STATUS_MISSING = 'missing';

    public const STATUS_CONFLICT = 'conflict';

    /**
     * @return array{
     *     status: string,
     *     norm: TimeNorm|null,
     *     scope: string|null,
     *     conflicts: array<int, TimeNorm>
     * }
     */
    public function select(WorkType $workType, Vehicle $vehicle): array
    {
        $vehicle->loadMissing([
            'modelReference.make',
            'generationReference.model',
            'engineReference.model',
            'transmissionReference',
            'driveReference',
        ]);

        $matches = TimeNorm::query()
            ->where('tenant_id', $workType->tenant_id)
            ->where('work_type_id', $workType->id)
            ->where('active', true)
            ->with([
                'vehicleMake',
                'vehicleModel.make',
                'vehicleGeneration.model',
                'vehicleEngineVariant.model',
            ])
            ->get()
            ->filter(fn (TimeNorm $norm): bool => $this->matches($norm, $vehicle))
            ->map(fn (TimeNorm $norm): array => [
                'norm' => $norm,
                'rank' => $this->rank($norm),
                'constraints' => $this->constraintCount($norm),
                'scope' => $this->scope($norm),
            ]);

        if ($matches->isEmpty()) {
            return $this->result(self::STATUS_MISSING);
        }

        $bestRank = (int) $matches->max('rank');
        $ranked = $matches->where('rank', $bestRank);
        $bestConstraints = $bestRank === 400
            ? (int) $ranked->max('constraints')
            : 0;
        $best = $ranked->where('constraints', $bestConstraints)->values();

        if ($best->count() !== 1) {
            return $this->result(
                self::STATUS_CONFLICT,
                conflicts: $best->pluck('norm')->all(),
            );
        }

        $selected = $best->first();

        return $this->result(
            self::STATUS_SELECTED,
            $selected['norm'],
            $selected['scope'],
        );
    }

    /**
     * @return array{
     *     status: string,
     *     norm: TimeNorm|null,
     *     scope: string|null,
     *     conflicts: array<int, TimeNorm>
     * }
     */
    private function result(
        string $status,
        ?TimeNorm $norm = null,
        ?string $scope = null,
        array $conflicts = [],
    ): array {
        return compact('status', 'norm', 'scope', 'conflicts');
    }

    private function matches(TimeNorm $norm, Vehicle $vehicle): bool
    {
        $model = $vehicle->modelReference;
        $makeId = $model?->vehicle_make_id;

        return $this->matchesNullableId($norm->vehicle_id, $vehicle->id)
            && $this->matchesNullableId($norm->vehicle_make_id, $makeId)
            && ($norm->vehicle_model_id === null || VehicleReferenceIdentity::sameModel($norm->vehicleModel, $model))
            && ($norm->vehicle_generation_id === null || VehicleReferenceIdentity::sameGeneration(
                $norm->vehicleGeneration,
                $vehicle->generationReference,
            ))
            && ($norm->vehicle_engine_variant_id === null || VehicleReferenceIdentity::sameEngine(
                $norm->vehicleEngineVariant,
                $vehicle->engineReference,
            ))
            && $this->matchesNullableId(
                $norm->vehicle_transmission_type_id,
                $vehicle->vehicle_transmission_type_id,
            )
            && $this->matchesNullableId(
                $norm->vehicle_drive_type_id,
                $vehicle->vehicle_drive_type_id,
            )
            && (
                $norm->modification_code === null
                || $norm->modification_code === ''
                || $norm->modification_code === $vehicle->modification_code
            );
    }

    private function matchesNullableId(?int $expected, ?int $actual): bool
    {
        return $expected === null || $expected === $actual;
    }

    private function rank(TimeNorm $norm): int
    {
        if ($norm->vehicle_id !== null) {
            return 500;
        }

        if (
            $norm->vehicle_generation_id !== null
        ) {
            return 400;
        }

        if ($norm->vehicle_make_id !== null && $norm->vehicle_model_id !== null) {
            return 300;
        }

        if ($norm->vehicle_make_id !== null) {
            return 200;
        }

        return 100;
    }

    private function hasModificationConstraint(TimeNorm $norm): bool
    {
        return $norm->vehicle_engine_variant_id !== null
            || $norm->vehicle_transmission_type_id !== null
            || $norm->vehicle_drive_type_id !== null
            || ($norm->modification_code !== null && $norm->modification_code !== '');
    }

    private function constraintCount(TimeNorm $norm): int
    {
        if ($this->rank($norm) !== 400) {
            return 0;
        }

        return Collection::make([
            $norm->vehicle_make_id,
            $norm->vehicle_model_id,
            $norm->vehicle_generation_id,
            $norm->vehicle_engine_variant_id,
            $norm->vehicle_transmission_type_id,
            $norm->vehicle_drive_type_id,
            $norm->modification_code,
        ])->filter(static fn (mixed $value): bool => $value !== null && $value !== '')->count();
    }

    private function scope(TimeNorm $norm): string
    {
        return match ($this->rank($norm)) {
            500 => 'vehicle',
            400 => $this->hasModificationConstraint($norm)
                ? 'generation_modification'
                : 'generation',
            300 => 'make_model',
            200 => 'make',
            default => 'general',
        };
    }
}
