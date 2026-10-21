<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Station;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Station>
 */
final class StationFactory extends Factory
{
    protected $model = Station::class;

    public function definition(): array
    {
        return [
            'uuid'            => (string) Str::uuid(),
            'tenant_id'       => Tenant::factory(),
            'organization_id' => Organization::factory(),
            'name'            => 'СТО ' . Str::upper(Str::random(4)),
            'address'         => 'г. Москва, ул. Станционная, д. 1',
            'contact'         => 'СТО Контакт',
            'rating'          => null,
            'active'          => true,
            'profile'         => [],
            'workday_start'   => '10:00:00',
            'workday_end'     => '20:00:00',
            'working_days'    => [1, 2, 3, 4, 5, 6, 7],
        ];
    }
}
