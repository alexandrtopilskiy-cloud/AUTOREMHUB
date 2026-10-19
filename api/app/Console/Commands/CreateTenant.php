<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\ReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateTenant extends Command
{
    protected $signature = 'sto:tenant-create
        {name : Название управляющей компании}';

    protected $description = 'Создать контур и организацию УК '
        . 'без учётных записей';

    /**
     * @throws Throwable
     */
    public function handle(ReferenceSeeder $seed): int
    {
        $name = trim((string) $this->argument('name'));

        if (($error = $this->validateInput($name))) {
            $this->error($error);

            return self::FAILURE;
        }

        $tenant = DB::transaction(function () use ($name, $seed): Tenant {
            $tenant = Tenant::query()->create(['name' => $name]);

            Organization::query()->create([
                'tenant_id' => $tenant->id,
                'kind'      => OrganizationKind::Manager,
                'name'      => $tenant->name,
            ]);

            $seed->seed($tenant->id);

            return $tenant;
        });

        $this->info('Создан контур УК. tenant_id=' . $tenant->id . ' UUID=' . $tenant->uuid);

        return self::SUCCESS;
    }

    /**
     * Проверяет входные данные. Возвращает текст ошибки или null.
     */
    private function validateInput(string $name): ?string
    {
        if ($name === '' || mb_strlen($name) > 255) {
            return 'Укажите название УК до 255 символов';
        }

        return null;
    }
}
