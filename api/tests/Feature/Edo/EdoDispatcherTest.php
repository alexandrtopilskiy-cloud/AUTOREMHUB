<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoProvider;
use App\Exceptions\EdoNotConnectedException;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\Edo\Drivers\Sbis\SbisProvider;
use App\Services\Edo\EdoDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Оператор ЭДО выбирается в .env (EDO_PROVIDER), а не настройкой организации.
 */
final class EdoDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_env_provider_wins_over_organization_provider(): void
    {
        config(['edo.mock' => false, 'edo.default' => 'sbis']);
        $organization = $this->organization(EdoProvider::Diadoc, null);

        $dispatcher = app(EdoDispatcher::class);
        $this->assertSame(EdoProvider::Sbis, $dispatcher->providerFor($organization));

        try {
            $dispatcher->forOrganization($organization);
            $this->fail('Ожидалось EdoNotConnectedException');
        } catch (EdoNotConnectedException $e) {
            $this->assertStringContainsString('«ООО Тест» не подключена к оператору ЭДО «СБИС»', $e->getMessage());
        }
    }

    public function test_connected_organization_gets_env_provider(): void
    {
        config(['edo.mock' => false, 'edo.default' => 'sbis']);
        $organization = $this->organization(EdoProvider::Sbis, 'login');

        $this->assertInstanceOf(SbisProvider::class, app(EdoDispatcher::class)->forOrganization($organization));
    }

    public function test_without_env_provider_organization_setting_is_used(): void
    {
        config(['edo.mock' => false, 'edo.default' => 'none']);
        $organization = $this->organization(EdoProvider::Sbis, 'login');

        $this->assertSame(EdoProvider::Sbis, app(EdoDispatcher::class)->providerFor($organization));
        $this->assertInstanceOf(SbisProvider::class, app(EdoDispatcher::class)->forOrganization($organization));
    }

    private function organization(EdoProvider $provider, ?string $clientId): Organization
    {
        $tenant = Tenant::query()->create(['name' => 'Test Tenant']);
        $organization = Organization::query()->create([
            'tenant_id' => $tenant->id, 'kind' => 'manager',
            'name' => 'ООО Тест', 'short_name' => 'ООО Тест',
            'inn' => '7701234567', 'kpp' => '771301001', 'ogrn' => '1157746123456',
            'contact' => 'Test', 'legal_address' => 'Moscow',
            'actual_address' => 'Moscow', 'postal_address' => 'Moscow',
            'director_name' => 'Ivanov', 'phone' => '+7', 'email' => 'uk@e.com',
            'bank_name' => 'T', 'bik' => '044525225',
            'settlement_account' => '40817810000000000001',
            'correspondent_account' => '30101810400000000225',
            'tax_regime' => 'osn', 'vat_rate' => 20.00,
        ]);
        $organization->forceFill(['edo_provider' => $provider, 'edo_client_id' => $clientId])->save();

        return $organization;
    }
}
