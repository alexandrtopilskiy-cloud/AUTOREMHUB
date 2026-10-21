<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
final class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name'      => 'Поставщик ' . $this->faker->unique()->numerify('####'),
            'active'    => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
