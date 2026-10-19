<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CatalogAuditService;
use App\Services\UserDirectoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeedTestUsers extends Command
{
    protected $signature = 'sto:test-users-create
        {--tenant= : Integer ID контура УК}
        {--reset-password : Сбросить пароль уже существующих тестовых логинов}';

    protected $description = 'Создать по 3 тестовых пользователя для ЛК УК, '
        . 'Заказчика (ООО «Альфа») и СТО (ООО «КУБ»)';

    public const PASSWORD = '123456789123456789';

    /**
     * Организация null — организация УК контура.
     *
     * @var array<int, array{login: string, organization: string|null, first: string, phone: string}>
     */
    public const USERS = [
        ['login' => 'UK', 'organization' => null, 'first' => 'УК', 'phone' => '+79990000001'],
        ['login' => 'UK1', 'organization' => null, 'first' => 'УК 1', 'phone' => '+79990000002'],
        ['login' => 'UK2', 'organization' => null, 'first' => 'УК 2', 'phone' => '+79990000003'],
        ['login' => 'Zakaz', 'organization' => 'ООО "Альфа"', 'first' => 'Заказчик', 'phone' => '+79990000011'],
        ['login' => 'Zakaz1', 'organization' => 'ООО "Альфа"', 'first' => 'Заказчик 1', 'phone' => '+79990000012'],
        ['login' => 'Zakaz2', 'organization' => 'ООО "Альфа"', 'first' => 'Заказчик 2', 'phone' => '+79990000013'],
        ['login' => 'STO', 'organization' => 'ООО "КУБ"', 'first' => 'СТО', 'phone' => '+79990000021'],
        ['login' => 'STO1', 'organization' => 'ООО "КУБ"', 'first' => 'СТО 1', 'phone' => '+79990000022'],
        ['login' => 'STO2', 'organization' => 'ООО "КУБ"', 'first' => 'СТО 2', 'phone' => '+79990000023'],
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

        $manager = Organization::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', OrganizationKind::Manager)
            ->orderBy('id')
            ->first();

        if ($manager === null) {
            $this->error('В контуре нет организации УК.');

            return self::FAILURE;
        }

        if ($this->call('sto:test-cabinets-create', ['--tenant' => $tenant->id]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $reset = (bool) $this->option('reset-password');
        $rows = [];

        DB::transaction(function () use ($tenant, $manager, $audit, $reset, &$rows): void {
            foreach (self::USERS as $spec) {
                $organization = $spec['organization'] === null
                    ? $manager
                    : Organization::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('name', $spec['organization'])
                        ->firstOrFail();
                $user = User::query()->where('login', $spec['login'])->first();

                if ($user !== null) {
                    if ($reset && $user->organization_id === $organization->id) {
                        $user->forceFill(['password' => self::PASSWORD, 'active' => true])->save();
                        $rows[] = [$spec['login'], $organization->name, 'пароль сброшен'];
                    } else {
                        $rows[] = [$spec['login'], $organization->name, 'уже есть, не изменён'];
                    }

                    continue;
                }

                $user = User::query()->create([
                    'organization_id'   => $organization->id,
                    'login'             => $spec['login'],
                    'display_name'      => 'Тестовый ' . $spec['first'],
                    'last_name'         => 'Тестовый',
                    'first_name'        => $spec['first'],
                    'middle_name'       => null,
                    'email'             => strtolower($spec['login']) . '@example.ru',
                    'phone'             => $spec['phone'],
                    'role'              => $organization->kind->userRole(),
                    'password'          => self::PASSWORD,
                    'active'            => true,
                    'registered_at'     => now(),
                    'access_revoked_at' => null,
                    'access_rights'     => UserDirectoryService::defaultRights($organization->kind),
                ]);
                $audit->record(
                    $tenant->id,
                    null,
                    CatalogKind::Users,
                    $user,
                    'test_account_upsert',
                    null,
                    [
                        'uuid'            => $user->uuid,
                        'organization_id' => $user->organization_id,
                        'login'           => $user->login,
                        'role'            => $user->role->value,
                        'access_rights'   => $user->access_rights,
                    ],
                );
                $rows[] = [$spec['login'], $organization->name, 'создан'];
            }
        });

        $this->table(['Логин', 'Организация', 'Результат'], $rows);
        $this->warn('Тестовый пароль общий для всех логинов. Не оставляйте эти учётные записи в рабочей системе.');

        return self::SUCCESS;
    }
}
