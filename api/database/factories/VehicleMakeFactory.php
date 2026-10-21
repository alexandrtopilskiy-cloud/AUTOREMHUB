<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VehicleMake;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleMake>
 */
final class VehicleMakeFactory extends Factory
{
    protected $model = VehicleMake::class;

    public function definition(): array
    {
        $suffix = Str::upper(Str::random(6));
        $name   = "Марка {$suffix}";

        return [
            'uuid'            => (string) Str::uuid(),
            'name'            => $name,
            'normalized_name' => mb_strtolower($name),
            'active'          => true,
        ];
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => [
            'name'            => $name,
            'normalized_name' => mb_strtolower($name),
        ]);
    }
}
