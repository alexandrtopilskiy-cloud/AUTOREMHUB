<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Station;
use App\Models\Tenant;
use App\Services\CatalogAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeedTestCabinets extends Command
{
    protected $signature = 'sto:test-cabinets-create
        {--tenant= : Integer ID контура УК}';

    protected $description = 'Создать тестовые кабинеты ООО «Альфа», '
        . '«Бетта», «КУБ» и «Овал»';

    /**
     * @var array<int, array{name: string, kind: OrganizationKind}>
     */
    private const CABINETS = [
        [
            'name'     => 'ООО "Альфа"',
            'kind'     => OrganizationKind::Customer,
        ],
        [
            'name'     => 'ООО "Бетта"',
            'kind'     => OrganizationKind::Customer,
        ],
        [
            'name'     => 'ООО "КУБ"',
            'kind'     => OrganizationKind::Station,
        ],
        [
            'name'     => 'ООО "Овал"',
            'kind'     => OrganizationKind::Station,
        ],
    ];

    /**
     * @throws Throwable
     */
    public function handle(CatalogAuditService $audit): int
    {
        $tenant = Tenant::query()->find($this->option('tenant'));

        if ($tenant === null) {
            $this->error('Укажите существующий --tenant.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($tenant, $audit): void {
            foreach (self::CABINETS as $cabinet) {
                $organization = Organization::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'kind'      => $cabinet['kind'],
                        'name'      => $cabinet['name'],
                    ],
                    ['contact' => ''],
                );

                $record = $organization;

                if ($cabinet['kind'] === OrganizationKind::Station) {
                    $record = Station::query()->firstOrCreate(
                        ['organization_id' => $organization->id],
                        [
                            'tenant_id' => $tenant->id,
                            'name'      => $cabinet['name'],
                            'address'   => '',
                            'contact'   => '',
                            'profile'   => [],
                            'active'    => true,
                        ],
                    );
                }

                $audit->record(
                    $tenant->id,
                    null,
                    $cabinet['kind'] === OrganizationKind::Station
                        ? CatalogKind::Stations
                        : CatalogKind::Customers,
                    $record,
                    'test_account_upsert',
                    null,
                    $record->attributesToArray(),
                );
            }
        });

        $this->info(
            'Тестовые организации созданы или обновлены. '
            . 'Перечни ТС Альфа и Бетта пустые.',
        );

        return self::SUCCESS;
    }
}
