<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PartManufacturer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartManufacturer>
 */
final class PartManufacturerFactory extends Factory
{
    protected $model = PartManufacturer::class;

    public function definition(): array
    {
        $name = 'Bosch ' . Str::upper(Str::random(4));

        return [
            'tenant_id'       => Tenant::factory(),
            'name'            => $name,
            'normalized_name' => mb_strtolower($name),
            'status'          => 'approved',
            'active'          => true,
            'revision'        => 1,
        ];
    }

    /**
     * Задать точное имя. Нормализованное имя пересчитывается автоматически.
     */
    public function named(string $name): self
    {
        return $this->state(fn (): array => [
            'name'            => $name,
            'normalized_name' => mb_strtolower($name),
        ]);
    }
}
