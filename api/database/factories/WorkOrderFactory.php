<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RepairRequest;
use App\Models\Station;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrder>
 */
final class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        return [
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => RepairRequest::factory(),
            'vehicle_id'        => Vehicle::factory(),
            'station_id'        => Station::factory(),
            'mileage'           => 50_000,
            'completed_at'      => now(),
            'total'             => 2000.00,
            'conclusion'        => 'Работы выполнены в полном объёме.',
            'estimate_version'  => 0,
        ];
    }
}
