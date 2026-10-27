<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RepairRequest>
 */
final class RepairRequestFactory extends Factory
{
    protected $model = RepairRequest::class;

    public function definition(): array
    {
        return [
            'public_id'                => (string) Str::uuid(),
            'uuid'                     => (string) Str::uuid(),
            'tenant_id'                => Tenant::factory(),
            'customer_organization_id' => Organization::factory(),
            'revision'                 => 0,
            'stage'                    => 0,
            'data'                     => [],
            'works'                    => [],
            'history'                  => [],
            'estimate_version'         => 0,
            'review_context'           => 'initial',
            'review_baseline_version'  => 0,
            'created_by'               => User::factory(),
        ];
    }
}
