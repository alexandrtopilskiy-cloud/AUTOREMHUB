<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Station;
use App\Models\Tenant;
use App\Services\VehicleImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateOrganization extends Command
{
    protected $signature = 'sto:organization-create
        {name : Название организации}
        {--tenant= : Integer ID контура УК}
        {--kind=customer : customer|station}
        {--vehicles= : XLSX/CSV перечень для Заказчика}
        {--address= : Адрес (только для station)}
        {--contact= : Контактное лицо}';

    protected $description = 'Добавить организацию Заказчика или СТО '
        . 'без учётных записей';

    /**
     * @throws Throwable
     */
    public function handle(VehicleImporter $importer): int
    {
        $name    = trim((string) $this->argument('name'));
        $kind    = OrganizationKind::tryFrom((string) $this->option('kind'));
        $contact = (string) $this->option('contact');

        if (($error = $this->validateInput($name, $kind, $contact))) {
            $this->error($error);

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($this->option('tenant'));

        if (!$tenant) {
            $this->error('Укажите существующий --tenant');

            return self::FAILURE;
        }

        if ($this->option('vehicles') && $kind !== OrganizationKind::Customer) {
            $this->error('Импорт предназначен для Заказчика');

            return self::FAILURE;
        }

        $org = DB::transaction(
            function () use ($tenant, $kind, $name, $contact, $importer): Organization {
                $org = Organization::query()->create([
                    'tenant_id' => $tenant->id,
                    'kind'      => $kind,
                    'name'      => $name,
                    'contact'   => $contact,
                ]);

                if ($kind === OrganizationKind::Station) {
                    Station::query()->create([
                        'organization_id' => $org->id,
                        'tenant_id'       => $tenant->id,
                        'name'            => $org->name,
                        'address'         => (string) $this->option('address'),
                        'contact'         => $contact,
                    ]);
                }

                if ($this->option('vehicles')) {
                    $importer->import($org, $this->option('vehicles'));
                }

                return $org;
            },
        );

        $this->info('Организация создана. id=' . $org->id . ' UUID=' . $org->uuid);

        return self::SUCCESS;
    }

    /**
     * Проверяет входные данные. Возвращает текст ошибки или null.
     */
    private function validateInput(
        string $name,
        ?OrganizationKind $kind,
        string $contact,
    ): ?string
    {
        if ($name === '' || mb_strlen($name) > 255) {
            return 'Укажите название организации до 255 символов';
        }

        if (!in_array($kind, [OrganizationKind::Customer, OrganizationKind::Station], true)) {
            return 'Укажите существующий --tenant и --kind=customer|station';
        }

        if (mb_strlen($contact) > 255) {
            return 'Контакт не должен превышать 255 символов';
        }

        return null;
    }
}
