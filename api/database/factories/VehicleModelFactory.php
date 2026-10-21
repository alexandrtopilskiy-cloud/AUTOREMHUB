<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleModel>
 */
final class VehicleModelFactory extends Factory
{
    protected $model = VehicleModel::class;

    public function definition(): array
    {
        $suffix = Str::upper(Str::random(6));
        $name   = "Модель {$suffix}";

        return [
            'uuid'                 => (string) Str::uuid(),
            'vehicle_make_id'      => VehicleMake::factory(),
            'name'                 => $name,
            'normalized_name'      => mb_strtolower($name),
            'model_year_from'      => 2020,
            'model_year_to'        => 2025,
            'engine_index'         => '',
            'body_code'            => '',
            'horsepower'           => null,
            'engine_volume_liters' => null,
            'transmission'         => null,
            'drive_type'           => null,
            'engine_type'          => null,
            'source_transmission'  => '',
            'source_drive_type'    => '',
            'source_engine_type'   => '',
            'fingerprint'          => hash('sha256', $suffix . '|' . microtime(true)),
            'active'               => true,
        ];
    }
}
