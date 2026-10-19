<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ReferenceSeeder;
use Illuminate\Console\Command;

final class SeedReferences extends Command
{
    protected $signature = 'sto:reference-seed
        {tenant : Integer ID контура УК}';

    protected $description = 'Добавить начальные причины обращения в БД без заявок и нормативных значений';

    public function handle(ReferenceSeeder $seed): int
    {
        $tenantId = (string) $this->argument('tenant');

        if (!ctype_digit($tenantId)) {
            $this->error('Укажите числовой ID контура УК');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find((int) $tenantId);

        if (!$tenant) {
            $this->error('Контур УК не найден');

            return self::FAILURE;
        }

        $seed->seed($tenant->id);

        $this->info('Причины добавлены');

        return self::SUCCESS;
    }
}