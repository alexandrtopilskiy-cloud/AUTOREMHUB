<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\User;
use App\Services\CatalogAuditService;
use App\Services\UserDirectoryService;
use App\Support\RussianPhone;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateUser extends Command
{
    protected $signature = 'sto:create-user
        {login : Логин учётной записи}
        {organization : Integer ID или UUID организации}
        {--last-name= : Фамилия}
        {--first-name= : Имя}
        {--middle-name= : Отчество}
        {--email= : Электронная почта}
        {--phone= : Телефон РФ}
        {--rights= : Права через запятую; по умолчанию права роли}';

    protected $description = 'Создать запись пользователя '
        . 'в существующей организации';

    private const MIN_LENGTH_PASSWORD = 12;
    private const MAX_LENGTH_PASSWORD = 72;

    public function handle(CatalogAuditService $audit): int
    {
        $login = trim((string) $this->argument('login'));
        $organizationKey = (string) $this->argument('organization');
        $organization = Organization::query()
            ->when(
                Str::isUuid($organizationKey),
                fn ($query) => $query->where('uuid', $organizationKey),
                fn ($query) => $query->whereKey((int) $organizationKey),
            )
            ->first();

        if ($organization === null) {
            $this->error('Организация не найдена.');

            return self::FAILURE;
        }

        if ($login === '' || mb_strlen($login) > 100) {
            $this->error('Логин должен содержать от 1 до 100 символов.');

            return self::FAILURE;
        }

        if (User::query()->where('login', $login)->exists()) {
            $this->error('Логин уже используется.');

            return self::FAILURE;
        }

        $lastName = trim((string) $this->option('last-name'));
        $firstName = trim((string) $this->option('first-name'));
        $middleName = trim((string) $this->option('middle-name'));
        $email = trim((string) $this->option('email'));
        $phone = RussianPhone::normalize((string) $this->option('phone'));

        if ($lastName === '' || $firstName === '') {
            $this->error('Укажите --last-name и --first-name.');

            return self::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Укажите корректный --email.');

            return self::FAILURE;
        }

        if ($phone === null) {
            $this->error('Укажите --phone в формате +7 (ххх) ххх хх хх.');

            return self::FAILURE;
        }

        $rights = $this->rights($organization->kind);

        if ($rights === null) {
            return self::FAILURE;
        }

        $password = $this->readPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $displayName = trim(implode(' ', array_filter([
            $lastName,
            $firstName,
            $middleName,
        ])));
        $user = User::query()->create([
            'organization_id'   => $organization->id,
            'login'             => $login,
            'display_name'      => $displayName,
            'last_name'         => $lastName,
            'first_name'        => $firstName,
            'middle_name'       => $middleName !== '' ? $middleName : null,
            'email'             => $email,
            'phone'             => $phone,
            'role'              => $organization->kind->userRole(),
            'password'          => $password,
            'active'            => true,
            'registered_at'     => now(),
            'access_revoked_at' => null,
            'access_rights'     => $rights,
        ]);
        $audit->record(
            $organization->tenant_id,
            null,
            CatalogKind::Users,
            $user,
            'create',
            null,
            [
                'uuid'              => $user->uuid,
                'organization_id'   => $user->organization_id,
                'last_name'         => $user->last_name,
                'first_name'        => $user->first_name,
                'middle_name'       => $user->middle_name,
                'email'             => $user->email,
                'login'             => $user->login,
                'phone'             => $user->phone,
                'role'              => $user->role->value,
                'active'            => $user->active,
                'registered_at'     => $user->registered_at?->toISOString(),
                'access_revoked_at' => null,
                'access_rights'     => $user->access_rights,
            ],
        );

        $this->info(sprintf('Создан пользователь %s (%s)', $user->id, $user->uuid));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function rights(OrganizationKind $role): ?array
    {
        $value = trim((string) $this->option('rights'));

        if ($value === '') {
            return UserDirectoryService::defaultRights($role);
        }

        $rights = array_values(array_filter(array_map('trim', explode(',', $value))));
        $unknown = array_diff($rights, UserDirectoryService::ACCESS_RIGHTS);

        if ($unknown !== []) {
            $this->error('Неизвестные права: ' . implode(', ', $unknown));

            return null;
        }

        return array_values(array_unique($rights));
    }

    private function readPassword(): ?string
    {
        $password = $this->secret(
            'Пароль (от ' . self::MIN_LENGTH_PASSWORD . ' до '
            . self::MAX_LENGTH_PASSWORD . ' байт)',
        );

        if (
            !is_string($password)
            || strlen($password) < self::MIN_LENGTH_PASSWORD
            || strlen($password) > self::MAX_LENGTH_PASSWORD
        ) {
            $this->error('Пароль должен содержать от 12 до 72 байт.');

            return null;
        }

        if ($password !== $this->secret('Повторите пароль')) {
            $this->error('Пароли не совпадают.');

            return null;
        }

        return $password;
    }
}
