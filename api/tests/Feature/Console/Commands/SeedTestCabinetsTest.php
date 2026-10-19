<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class SeedTestCabinetsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_creates_isolated_organizations_and_stations(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->artisan('sto:test-cabinets-create', [
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        // Проверяем организации
        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО "Альфа"',
        ]);
        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО "Бетта"',
        ]);
        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenant->id,
            'kind'      => 'station',
            'name'      => 'ООО "КУБ"',
        ]);
        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenant->id,
            'kind'      => 'station',
            'name'      => 'ООО "Овал"',
        ]);

        // Станции создаются только для kind=station
        $this->assertSame(
            2,
            Station::query()
            ->whereIn('name', ['ООО "КУБ"', 'ООО "Овал"'])
                ->count(),
        );

        // Пользователи не создаются
        $this->assertSame(0, User::query()->count());
    }

    public function test_fails_for_unknown_tenant(): void
    {
        $this->artisan('sto:test-cabinets-create', [
            '--tenant' => 999999,
        ])->assertFailed();
    }
}