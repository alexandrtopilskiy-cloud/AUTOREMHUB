<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DomainInput as Input;
use App\Support\RussianPhone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class UserDirectoryImporter
{
    private const HEADERS = [
        'cabinet'      => ['лк', 'кабинет'],
        'organization' => ['организация', 'принадлежность к организации'],
        'last_name'    => ['фамилия'],
        'first_name'   => ['имя'],
        'middle_name'  => ['отчество'],
        'phone'        => ['мобильный телефон', 'сотовый телефон', 'телефон'],
        'email'        => ['электронная почта', 'email', 'e-mail'],
        'login'        => ['логин'],
        'password'     => ['пароль'],
        'registered'   => ['дата первичной регистрации'],
        'revoked'      => ['дата отзыва доступа'],
        'active'       => ['активен', 'активе/неактивен'],
        'rights'       => ['права доступа'],
    ];

    private const LAST_NAMES = [
        'Иванов', 'Петров', 'Сидоров', 'Смирнов', 'Кузнецов', 'Попов',
        'Васильев', 'Соколов', 'Михайлов', 'Новиков', 'Фёдоров', 'Морозов',
        'Волков', 'Алексеев', 'Лебедев', 'Семёнов', 'Егоров', 'Павлов',
    ];

    private const FIRST_NAMES = [
        'Алексей', 'Максим', 'Иван', 'Артём', 'Дмитрий', 'Никита',
        'Михаил', 'Сергей', 'Андрей', 'Роман', 'Кирилл', 'Виктор',
        'Антон', 'Евгений', 'Олег', 'Илья', 'Павел', 'Денис',
    ];

    private const MIDDLE_NAMES = [
        'Александрович', 'Сергеевич', 'Иванович', 'Андреевич',
        'Дмитриевич', 'Михайлович', 'Викторович', 'Павлович',
        'Олегович', 'Романович', 'Евгеньевич', 'Николаевич',
    ];

    public function __construct(
        private readonly XlsxTableReader $reader,
        private readonly CatalogAuditService $audit,
        private readonly DocumentOwnerReassigner $ownerReassigner,
        private readonly DocumentLockService $documentLocks,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function import(Tenant $tenant, string $path, bool $dryRun = false): array
    {
        $rows = $this->reader->read($path, [
            self::HEADERS['organization'],
            self::HEADERS['login'],
            self::HEADERS['password'],
            self::HEADERS['email'],
        ]);
        [$headerIndex, $columns] = $this->header($rows);
        $records = [];

        foreach (array_slice($rows, $headerIndex + 1) as $offset => $row) {
            if ($this->isEmpty($row)) {
                continue;
            }

            $records[] = $this->record(
                $tenant,
                $columns,
                $row,
                $headerIndex + $offset + 2,
            );
        }

        if ($records === []) {
            Input::fail('В XLSX нет пользователей');
        }

        $logins = array_column($records, 'login');

        if (count($logins) !== count(array_unique($logins))) {
            Input::fail('В XLSX логины не должны повторяться');
        }

        $result = [
            'total'       => count($records),
            'created'     => 0,
            'updated'     => 0,
            'revoked'     => 0,
            'randomized'  => count(array_filter(
                $records,
                static fn (array $record): bool => $record['randomized'],
            )),
            'reassigned'  => 0,
        ];

        if ($dryRun) {
            return $result;
        }

        return DB::transaction(function () use ($tenant, $records, $result): array {
            $importedIds = [];
            $firstUserByOrganization = [];
            $formerUserIdsByOrganization = [];

            foreach ($records as $record) {
                $existing = User::query()
                    ->with('organization')
                    ->where('login', $record['login'])
                    ->first();

                if (
                    $existing !== null
                    && $existing->organization_id !== $record['organization']->id
                ) {
                    if ($existing->organization->tenant_id !== $tenant->id) {
                        Input::fail(
                            "Логин «{$record['login']}» используется в другом контуре",
                        );
                    }

                    $formerUserIdsByOrganization[$existing->organization_id][] =
                        $existing->id;
                    $this->retireConflictingLogin($tenant, $existing);
                    $result['revoked']++;
                    $existing = null;
                }

                $user = $existing ?? new User();
                $before = $user->exists ? $this->auditSnapshot($user) : null;
                $passwordChanged = !$user->exists
                    || !Hash::check($record['password'], (string) $user->password);

                $user->organization_id = $record['organization']->id;
                $user->last_name = $record['last_name'];
                $user->first_name = $record['first_name'];
                $user->middle_name = $record['middle_name'];
                $user->display_name = trim(implode(' ', [
                    $record['last_name'],
                    $record['first_name'],
                    $record['middle_name'],
                ]));
                $user->email = $record['email'];
                $user->login = $record['login'];
                $user->phone = $record['phone'];
                $user->role = $record['organization']->kind->userRole();
                $user->active = $record['active'];
                $user->registered_at = $record['registered_at'];
                $user->access_revoked_at = $record['access_revoked_at'];
                $user->access_rights = $record['access_rights'];

                if ($passwordChanged) {
                    $user->password = $record['password'];
                }

                $user->save();

                if ($passwordChanged || !$user->hasPlatformAccess()) {
                    $user->tokens()->delete();
                    $this->documentLocks->releaseAll($user);
                }

                $this->audit->record(
                    $tenant->id,
                    null,
                    CatalogKind::Users,
                    $user,
                    $before === null ? 'import_create' : 'import_update',
                    $before,
                    $this->auditSnapshot($user),
                );

                $result[$before === null ? 'created' : 'updated']++;
                $importedIds[] = $user->id;
                $firstUserByOrganization[$user->organization_id] ??= $user;
            }

            $legacyUsers = User::query()
                ->whereHas(
                    'organization',
                    fn ($query) => $query->where('tenant_id', $tenant->id),
                )
                ->whereNotIn('id', $importedIds)
                ->get();

            foreach ($legacyUsers as $legacyUser) {
                $formerUserIdsByOrganization[$legacyUser->organization_id][] =
                    $legacyUser->id;

                if ($legacyUser->active || $legacyUser->access_revoked_at === null) {
                    $before = $this->auditSnapshot($legacyUser);
                    $legacyUser->active = false;
                    $legacyUser->access_revoked_at ??= now();
                    $legacyUser->save();
                    $legacyUser->tokens()->delete();
                    $this->documentLocks->releaseAll($legacyUser);
                    $this->audit->record(
                        $tenant->id,
                        null,
                        CatalogKind::Users,
                        $legacyUser,
                        'import_revoke',
                        $before,
                        $this->auditSnapshot($legacyUser),
                    );
                    $result['revoked']++;
                }
            }

            foreach ($firstUserByOrganization as $organizationId => $owner) {
                $organization = Organization::query()->findOrFail($organizationId);
                $result['reassigned'] += $this->ownerReassigner->reassign(
                    $organization,
                    $owner,
                    $formerUserIdsByOrganization[$organizationId] ?? [],
                );
            }

            return $result;
        });
    }

    /**
     * @param array<int, array<int, string>> $rows
     *
     * @return array{0: int, 1: array<string, int>}
     */
    private function header(array $rows): array
    {
        foreach ($rows as $rowIndex => $row) {
            $normalized = array_map(
                static fn (string $value): string => XlsxTableReader::normalizeHeader($value),
                $row,
            );
            $columns = [];

            foreach (self::HEADERS as $key => $aliases) {
                foreach ($aliases as $alias) {
                    $index = array_search(
                        XlsxTableReader::normalizeHeader($alias),
                        $normalized,
                        true,
                    );

                    if ($index !== false) {
                        $columns[$key] = (int) $index;
                        break;
                    }
                }
            }

            if (isset(
                $columns['organization'],
                $columns['email'],
                $columns['login'],
                $columns['password'],
            )) {
                return [$rowIndex, $columns];
            }
        }

        Input::fail('В XLSX не найдена строка заголовков');
    }

    /**
     * @param array<string, int> $columns
     * @param array<int, string> $row
     *
     * @return array<string, mixed>
     */
    private function record(
        Tenant $tenant,
        array $columns,
        array $row,
        int $rowNumber,
    ): array {
        $value = static fn (string $key): string => trim(
            (string) ($row[$columns[$key] ?? -1] ?? ''),
        );
        $organizationName = $value('organization');
        $organization = $this->organization($tenant, $organizationName, $value('cabinet'));
        $login = $value('login');
        $email = $value('email');
        $password = $value('password');

        if ($login === '' || mb_strlen($login) > 100) {
            Input::fail("Строка {$rowNumber}: укажите логин до 100 символов");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Input::fail("Строка {$rowNumber}: некорректная электронная почта");
        }

        if (strlen($password) < 12 || strlen($password) > 72) {
            Input::fail("Строка {$rowNumber}: пароль должен содержать 12–72 байта");
        }

        $lastName = $value('last_name');
        $firstName = $value('first_name');
        $middleName = $value('middle_name');
        $phone = RussianPhone::normalize($value('phone'));
        $randomized = false;

        if ($lastName === '') {
            $lastName = $this->generated(self::LAST_NAMES, $login, 0);
            $randomized = true;
        }

        if ($firstName === '') {
            $firstName = $this->generated(self::FIRST_NAMES, $login, 1);
            $randomized = true;
        }

        if ($middleName === '') {
            $middleName = $this->generated(self::MIDDLE_NAMES, $login, 2);
            $randomized = true;
        }

        if ($phone === null) {
            if ($value('phone') !== '') {
                Input::fail(
                    "Строка {$rowNumber}: телефон должен иметь формат "
                    . '+7 (ххх) ххх хх хх',
                );
            }

            $phone = $this->generatedPhone($login);
            $randomized = true;
        }

        $accessRevokedAt = $this->date($value('revoked'), $rowNumber, false);
        $active = $this->boolean($value('active'), true);

        return [
            'organization'      => $organization,
            'last_name'         => $lastName,
            'first_name'        => $firstName,
            'middle_name'       => $middleName,
            'phone'             => $phone,
            'email'             => $email,
            'login'             => $login,
            'password'          => $password,
            'registered_at'     => $this->date(
                $value('registered'),
                $rowNumber,
                true,
            ),
            'access_revoked_at' => $accessRevokedAt,
            'active'            => $active,
            'access_rights'     => $this->rights(
                $value('rights'),
                $organization->kind,
                $rowNumber,
            ),
            'randomized'        => $randomized,
        ];
    }

    private function organization(
        Tenant $tenant,
        string $name,
        string $cabinet,
    ): Organization {
        $organizations = Organization::query()
            ->where('tenant_id', $tenant->id)
            ->get();
        $normalized = $this->normalizeOrganization($name);
        $organization = $organizations->first(
            fn (Organization $item): bool => $this->normalizeOrganization($item->name)
                === $normalized,
        );

        if ($organization !== null) {
            if ($organization->kind !== $this->role($cabinet)) {
                Input::fail(
                    "Организация «{$name}» не соответствует типу ЛК «{$cabinet}»",
                );
            }

            return $organization;
        }

        $role = $this->role($cabinet);
        $byRole = $organizations->where('kind', $role);

        if ($role === OrganizationKind::Manager && $byRole->count() === 1) {
            return $byRole->first();
        }

        Input::fail("Организация «{$name}» не найдена в контуре {$tenant->id}");
    }

    private function role(string $cabinet): OrganizationKind
    {
        $value = mb_strtolower($cabinet);

        return match (true) {
            str_contains($value, 'сто') => OrganizationKind::Station,
            str_contains($value, 'ук') => OrganizationKind::Manager,
            str_contains($value, 'заказ'),
            str_contains($value, 'закач') => OrganizationKind::Customer,
            default => Input::fail("Неизвестный тип личного кабинета «{$cabinet}»"),
        };
    }

    /**
     * @return array<int, string>
     */
    private function rights(
        string $value,
        OrganizationKind $role,
        int $rowNumber,
    ): array
    {
        if ($value === '' || in_array(mb_strtolower($value), [
            'uk', 'ук', 'sto', 'сто', 'zakaz', 'заказ',
        ], true)) {
            return UserDirectoryService::defaultRights($role);
        }

        $rights = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;\n]+/u', $value) ?: [],
        )));
        $unknown = array_diff($rights, UserDirectoryService::ACCESS_RIGHTS);

        if ($unknown !== []) {
            Input::fail(
                "Строка {$rowNumber}: неизвестные права: " . implode(', ', $unknown),
            );
        }

        return array_values(array_unique($rights));
    }

    private function date(string $value, int $rowNumber, bool $defaultNow): ?CarbonImmutable
    {
        if ($value === '') {
            return $defaultNow ? CarbonImmutable::now() : null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return CarbonImmutable::create(1899, 12, 30)
                ->addDays((int) floor((float) $value))
                ->addSeconds((int) round(fmod((float) $value, 1.0) * 86400));
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            Input::fail("Строка {$rowNumber}: некорректная дата «{$value}»");
        }
    }

    private function boolean(string $value, bool $default): bool
    {
        if ($value === '') {
            return $default;
        }

        return in_array(mb_strtolower($value), ['1', 'да', 'yes', 'true', 'активен'], true);
    }

    /**
     * @param array<int, string> $values
     */
    private function generated(array $values, string $login, int $offset): string
    {
        $hash = (int) sprintf('%u', crc32($login . ':' . $offset));

        return $values[$hash % count($values)];
    }

    private function generatedPhone(string $login): string
    {
        $hash = hash('sha256', 'autoremhub-user-phone:' . $login);
        $number = str_pad(
            (string) (hexdec(substr($hash, 0, 8)) % 1_000_000_000),
            9,
            '0',
            STR_PAD_LEFT,
        );

        return '+79' . $number;
    }

    private function retireConflictingLogin(Tenant $tenant, User $user): void
    {
        $before = $this->auditSnapshot($user);
        $suffix = substr(str_replace('-', '', $user->uuid), 0, 8);
        $user->login = Str::limit('revoked-' . $suffix . '-' . $user->login, 100, '');
        $user->active = false;
        $user->access_revoked_at ??= now();
        $user->save();
        $user->tokens()->delete();
        $this->documentLocks->releaseAll($user);
        $this->audit->record(
            $tenant->id,
            null,
            CatalogKind::Users,
            $user,
            'import_revoke',
            $before,
            $this->auditSnapshot($user),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(User $user): array
    {
        return [
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
            'access_revoked_at' => $user->access_revoked_at?->toISOString(),
            'access_rights'     => $user->access_rights,
        ];
    }

    /**
     * @param array<int, string> $row
     */
    private function isEmpty(array $row): bool
    {
        return collect($row)->every(static fn (string $value): bool => trim($value) === '');
    }

    private function normalizeOrganization(string $value): string
    {
        $value = str_replace(
            ['«', '»', '“', '”', '„', 'ё'],
            ['"', '"', '"', '"', '"', 'е'],
            mb_strtolower($value),
        );

        return preg_replace('/[^a-zа-я0-9]+/u', '', $value) ?? '';
    }
}
