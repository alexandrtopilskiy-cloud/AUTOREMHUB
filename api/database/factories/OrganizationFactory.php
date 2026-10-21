<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $suffix = Str::upper(Str::random(4));

        return [
            'tenant_id'             => Tenant::factory(),
            'kind'                  => 'manager',
            'name'                  => "ООО «Тест-{$suffix}»",
            'short_name'            => "Тест-{$suffix}",
            'inn'                   => '77' . $this->digits(8),
            'kpp'                   => '77' . $this->digits(7),
            'ogrn'                  => $this->digits(13),
            'contact'               => 'Иванов И.И.',
            'legal_address'         => 'г. Москва, ул. Тестовая, д. 1',
            'actual_address'        => 'г. Москва, ул. Тестовая, д. 1',
            'postal_address'        => 'г. Москва, ул. Тестовая, д. 1',
            'director_name'         => 'Иванов Иван Иванович',
            'phone'                 => '+7' . $this->digits(10),
            'email'                 => "test-{$suffix}@example.com",
            'bank_name'             => 'Тест-Банк',
            'bik'                   => '04' . $this->digits(7),
            'settlement_account'    => '40817' . $this->digits(15),
            'correspondent_account' => '30101' . $this->digits(15),
            'tax_regime'            => 'osn',
            'vat_rate'              => 20.00,
            'edo_provider'          => null,
            'edo_client_id'         => null,
            'edo_edi_code'          => null,
            'edo_registered_at'     => null,
            'markup_percent'        => null,
        ];
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
