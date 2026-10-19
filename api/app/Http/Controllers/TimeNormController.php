<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TimeNorm;
use App\Models\Vehicle;
use App\Models\WorkType;
use App\Services\TimeNormSelectionService;
use App\Services\WorkCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TimeNormController
{
    public function __construct(
        private readonly TimeNormSelectionService $selector,
        private readonly WorkCostService $cost,
    ) {
    }

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workTypeId' => ['required', 'uuid'],
            'vehicleId' => ['required', 'uuid'],
            'laborRateType' => ['sometimes', 'in:general,official,other'],
            'quantity' => ['sometimes', 'numeric', 'min:0.001', 'max:1000000'],
        ]);
        $user = $request->user();
        $work = WorkType::query()
            ->where('tenant_id', $user->tenantId())
            ->where('uuid', $validated['workTypeId'])
            ->where('active', true)
            ->firstOrFail();
        $vehicle = Vehicle::forUser($user)
            ->where('uuid', $validated['vehicleId'])
            ->firstOrFail();
        $rateType = $validated['laborRateType'] ?? 'general';
        $vehicle->labor_hour_rate = $vehicle->laborRate($rateType);
        $selection = $this->selector->select($work, $vehicle);
        $norm = $selection['norm'];
        $calculation = $this->calculation(
            $norm,
            $vehicle,
            (string) ($validated['quantity'] ?? '1'),
        );

        return response()->json(['result' => [
            'status' => $selection['status'],
            'scope' => $selection['scope'],
            'norm' => $norm === null ? null : [
                'id' => $norm->uuid,
                'normId' => $norm->norm_id,
                'hours' => $norm->hours === null ? null : (float) $norm->hours,
                'source' => $norm->source,
                'applicabilityLabel' => \App\Support\TimeNormDescription::label($norm),
            ],
            'conflicts' => array_map(
                static fn (TimeNorm $item): array => [
                    'id' => $item->uuid,
                    'normId' => $item->norm_id,
                    'hours' => $item->hours === null ? null : (float) $item->hours,
                    'source' => $item->source,
                ],
                $selection['conflicts'],
            ),
            'laborHourRate' => $vehicle->labor_hour_rate === null
                ? null
                : (float) $vehicle->labor_hour_rate,
            'unitPrice' => $calculation['unitPrice'] ?? null,
            'amount' => $calculation['amount'] ?? null,
        ]]);
    }

    /** @return array{unitPrice: string, amount: string}|null */
    private function calculation(
        ?TimeNorm $norm,
        Vehicle $vehicle,
        string $quantity,
    ): ?array {
        if ($norm?->hours === null || $vehicle->labor_hour_rate === null) {
            return null;
        }

        return $this->cost->calculate(
            (string) $norm->hours,
            (string) $vehicle->labor_hour_rate,
            $quantity,
        );
    }
}
