<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vehicle>
 */
final class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        $vin = strtoupper(Str::random(17));

        return [
            'uuid'                     => (string) Str::uuid(),
            'tenant_id'                => Tenant::factory(),
            'organization_id'          => Organization::factory(),
            'name'                     => 'Тестовое авто',
            'vin'                      => $vin,
            'plate'                    => '',
            'plate_normalized'         => $vin,
            'year'                     => 2020,
            'baseline_service_mileage' => null,
            'baseline_service_at'      => null,
            'labor_rate_coefficient'   => 1.0,
            'labor_hour_rate'          => null,
            'active'                   => true,
            'make'                     => 'Тест',
            'model'                    => 'Тест-Модель',
        ];
    }
}
