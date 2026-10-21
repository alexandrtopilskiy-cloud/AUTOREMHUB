<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $suffix = Str::lower(Str::random(6));

        return [
            'organization_id'   => Organization::factory(),
            'login'             => "user_{$suffix}",
            'display_name'      => "Пользователь {$suffix}",
            'role'              => 'manager',
            'password'          => Hash::make('password'),
            'active'            => true,
            'uuid'              => (string) Str::uuid(),
            'last_name'         => 'Тестов',
            'first_name'        => 'Тест',
            'middle_name'       => 'Тестович',
            'email'             => "user-{$suffix}@example.com",
            'phone'             => '+7' . $this->digits(10),
            'registered_at'     => now(),
            'access_revoked_at' => null,
            'access_rights'     => [],
        ];
    }

    public function customer(): self
    {
        return $this->state(fn () => ['role' => 'customer']);
    }

    public function station(): self
    {
        return $this->state(fn () => ['role' => 'station']);
    }

    public function manager(): self
    {
        return $this->state(fn () => ['role' => 'manager']);
    }

    private function digits(int $n): string
    {
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }
}
