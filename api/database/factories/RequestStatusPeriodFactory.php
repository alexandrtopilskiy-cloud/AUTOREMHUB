<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RequestStatusPeriod>
 */
final class RequestStatusPeriodFactory extends Factory
{
    protected $model = RequestStatusPeriod::class;

    public function definition(): array
    {
        return [
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => RepairRequest::factory(),
            'stage'             => 1,
            'entered_at'        => now()->subHour(),
            'exited_at'         => null,
            'actor_id'          => User::factory(),
        ];
    }

    public function closed(): self
    {
        return $this->state(fn () => [
            'entered_at' => now()->subHours(2),
            'exited_at'  => now()->subHour(),
        ]);
    }
}
