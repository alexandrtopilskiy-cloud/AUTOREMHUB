<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Vehicle;
use App\Services\WorkCostService;
use PHPUnit\Framework\TestCase;

final class VehicleLaborRatesTest extends TestCase
{
    public function test_each_tariff_uses_the_existing_formula(): void
    {
        $vehicle = new Vehicle();
        $vehicle->forceFill([
            'labor_hour_rate' => 2000, 'labor_hour_rate_official' => 3000,
            'labor_hour_rate_other' => 1500, 'labor_rate_coefficient' => 1.59,
        ]);
        $calculator = new WorkCostService();
        foreach (['general' => '6000.00', 'official' => '9000.00', 'other' => '4500.00'] as $type => $amount) {
            $this->assertSame($amount, $calculator->calculate('1.5', $vehicle->laborRate($type), '2')['amount']);
        }
    }

    public function test_missing_tariff_does_not_fall_back_to_general_or_zero(): void
    {
        $vehicle = new Vehicle();
        $vehicle->labor_hour_rate = 2000;
        $this->assertNull($vehicle->laborRate('official'));
        $this->assertNull($vehicle->laborRate('other'));
    }
}
