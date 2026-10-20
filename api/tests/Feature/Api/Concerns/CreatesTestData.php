<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Models\WorkType;
use App\Services\ReferenceSeeder;
use Illuminate\Support\Str;

trait CreatesTestData
{
    protected Tenant $tenant;
    protected array $actors = [];
    protected Vehicle $vehicle;
    protected VehicleContact $contact;
    protected VehicleContact $driver;
    protected Station $station;
    protected Supplier $supplier;
    protected ServiceReason $reason;
    protected ServiceReason $subtype;
    protected WorkType $work;

    protected function setUpTestData(): void
    {
        $this->tenant = Tenant::create(['name' => 'Тестовая УК']);

        foreach (['customer', 'manager', 'station'] as $role) {
            $org = Organization::create([
                'tenant_id' => $this->tenant->id,
                'kind'      => $role,
                'name'      => $role === 'customer' ? 'ООО "Тест"' : $role,
            ]);

            $this->actors[$role] = User::create([
                'organization_id' => $org->id,
                'login'           => $role . '-' . Str::random(10),
                'display_name'    => $role,
                'role'            => $role,
                'password'        => 'test-password-long',
                'active'          => true,
            ]);
        }

        $this->vehicle = Vehicle::create([
            'tenant_id'        => $this->tenant->id,
            'organization_id'  => $this->actors['customer']->organization_id,
            'name'             => 'Автомобиль',
            'vin'              => 'LVUDB21B2RA042817',
            'plate'            => 'А123АА178',
            'plate_normalized' => 'А123АА178',
            'year'             => 2026,
            'labor_hour_rate'  => 100,
        ]);

        $contactPerson = OrganizationPerson::create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->actors['customer']->organization_id,
            'kind'            => 'contact',
            'last_name'       => 'Иванов',
            'first_name'      => 'Иван',
            'middle_name'     => 'Иванович',
            'phone'           => '+79991234567',
        ]);

        $driverPerson = OrganizationPerson::create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->actors['customer']->organization_id,
            'kind'            => 'driver',
            'last_name'       => 'Петров',
            'first_name'      => 'Пётр',
            'middle_name'     => 'Петрович',
            'phone'           => '+79997654321',
        ]);

        $this->contact = VehicleContact::create([
            'vehicle_id'             => $this->vehicle->id,
            'organization_person_id' => $contactPerson->id,
            'kind'                   => 'contact',
            'last_name'              => 'Иванов',
            'first_name'             => 'Иван',
            'middle_name'            => 'Иванович',
            'phone'                  => '+79991234567',
        ]);

        $this->driver = VehicleContact::create([
            'vehicle_id'             => $this->vehicle->id,
            'organization_person_id' => $driverPerson->id,
            'kind'                   => 'driver',
            'last_name'              => 'Петров',
            'first_name'             => 'Пётр',
            'middle_name'            => 'Петрович',
            'phone'                  => '+79997654321',
        ]);

        $this->station = Station::create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->actors['station']->organization_id,
            'name'            => 'СТО',
            'address'         => 'Адрес',
        ]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Поставщик',
        ]);

        $this->work = WorkType::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Диагностика',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);

        app(ReferenceSeeder::class)->seed($this->tenant->id);

        $this->reason = ServiceReason::where('tenant_id', $this->tenant->id)
            ->where('code', 'diagnostics')
            ->firstOrFail();

        $this->subtype = ServiceReason::where('parent_id', $this->reason->id)->firstOrFail();
    }
}
