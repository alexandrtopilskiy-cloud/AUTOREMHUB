<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EdoRegisterOrganizationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $key = EdoProviderEnum::SberKorus->value; // 'sberkorus'

        config()->set('edo.default', $key);
        config()->set("edo.providers.{$key}.base_url", 'https://courier.example.test');
        config()->set("edo.providers.{$key}.username", 'test_login');
        config()->set("edo.providers.{$key}.password", 'test_password');
    }

    public function test_registers_organization_successfully(): void
    {
        $tenant = Tenant::factory()->create();
        $org = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'kind'      => 'station',
            'inn'       => '7701234567',
            'kpp'       => '770101001',
        ]);

        Http::fake([
            'courier.example.test/api/auth/logon'            => Http::response(['Token' => str_repeat('A', 100)], 200),
            'courier.example.test/api/dictionary/findClient' => Http::response([
                'Id'           => 12345,
                'Code'         => '2BK-7701234567-12345',
                'OperatorCode' => '2BK',
                'Inn'          => '7701234567',
                'Kpp'          => '770101001',
                'ShortName'    => 'ООО "Ромашка"',
                'FullName'     => 'Общество с ограниченной ответственностью "Ромашка"',
            ], 200),
        ]);

        $this->artisan('edo:register-organization', ['organization_id' => $org->id])
            ->assertExitCode(0);

        $org->refresh();

        $this->assertSame('12345', $org->edo_client_id);
        $this->assertSame('2BK-7701234567-12345', $org->edo_edi_code);
        $this->assertSame(EdoProviderEnum::SberKorus, $org->edo_provider);
        $this->assertNotNull($org->edo_registered_at);
    }

    public function test_skips_already_registered_without_force(): void
    {
        $tenant = Tenant::factory()->create();
        $org = Organization::factory()->create([
            'tenant_id'     => $tenant->id,
            'kind'          => 'station',
            'inn'           => '7701234567',
            'edo_client_id' => 'old-id',
            'edo_edi_code'  => 'old-code',
        ]);

        Http::fake();

        $this->artisan('edo:register-organization', ['organization_id' => $org->id])
            ->assertExitCode(0);

        $org->refresh();
        $this->assertSame('old-id', $org->edo_client_id);
        $this->assertSame('old-code', $org->edo_edi_code);
        Http::assertNothingSent();
    }

    public function test_force_overwrites_existing(): void
    {
        $tenant = Tenant::factory()->create();
        $org = Organization::factory()->create([
            'tenant_id'     => $tenant->id,
            'kind'          => 'station',
            'inn'           => '7701234567',
            'edo_client_id' => 'old-id',
            'edo_edi_code'  => 'old-code',
        ]);

        Http::fake([
            'courier.example.test/api/auth/logon'            => Http::response(['Token' => str_repeat('A', 100)], 200),
            'courier.example.test/api/dictionary/findClient' => Http::response([
                'Id'           => 99999,
                'Code'         => '2BK-7701234567-99999',
                'OperatorCode' => '2BK',
                'Inn'          => '7701234567',
                'Kpp'          => '770101001',
                'ShortName'    => 'ООО "Ромашка"',
            ], 200),
        ]);

        $this->artisan('edo:register-organization', [
            'organization_id' => $org->id,
            '--force'         => true,
        ])->assertExitCode(0);

        $org->refresh();
        $this->assertSame('99999', $org->edo_client_id);
        $this->assertSame('2BK-7701234567-99999', $org->edo_edi_code);
    }

    public function test_dry_run_does_not_write(): void
    {
        $tenant = Tenant::factory()->create();
        $org = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'kind'      => 'station',
            'inn'       => '7701234567',
        ]);

        Http::fake([
            'courier.example.test/api/auth/logon'            => Http::response(['Token' => str_repeat('A', 100)], 200),
            'courier.example.test/api/dictionary/findClient' => Http::response([
                'Id'           => 12345,
                'Code'         => '2BK-7701234567-12345',
                'OperatorCode' => '2BK',
                'Inn'          => '7701234567',
                'Kpp'          => '770101001',
                'ShortName'    => 'ООО "Ромашка"',
            ], 200),
        ]);

        $this->artisan('edo:register-organization', [
            'organization_id' => $org->id,
            '--dry-run'       => true,
        ])->assertExitCode(0);

        $org->refresh();
        $this->assertNull($org->edo_client_id);
        $this->assertNull($org->edo_edi_code);
    }

    public function test_fails_when_not_found_in_edo(): void
    {
        $tenant = Tenant::factory()->create();
        $org = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'kind'      => 'station',
            'inn'       => '7709999999',
        ]);

        Http::fake([
            'courier.example.test/api/auth/logon'            => Http::response(['Token' => str_repeat('A', 100)], 200),
            'courier.example.test/api/dictionary/findClient' => Http::response([], 200),
        ]);

        $this->artisan('edo:register-organization', ['organization_id' => $org->id])
            ->assertExitCode(1);

        $org->refresh();
        $this->assertNull($org->edo_client_id);
    }

    public function test_fails_when_no_inn(): void
    {
        $tenant = Tenant::factory()->create();
        $org = Organization::factory()->create([
            'tenant_id' => $tenant->id,
            'kind'      => 'station',
            'inn'       => '',
        ]);

        Http::fake();

        $this->artisan('edo:register-organization', ['organization_id' => $org->id])
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_fails_when_organization_not_found(): void
    {
        Http::fake();

        $this->artisan('edo:register-organization', ['organization_id' => 999999])
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}
