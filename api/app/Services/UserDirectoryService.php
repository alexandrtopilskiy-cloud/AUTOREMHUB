<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Support\DomainInput as Input;
use App\Support\RussianPhone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UserDirectoryService
{
    public const PASSWORD_MASK = '••••••••••••';

    public const ACCESS_RIGHTS = [
        'requests.view',
        'requests.create',
        'requests.manage',
        'requests.station',
        'catalogs.vehicles',
        'catalogs.people',
        'catalogs.manage',
        'users.manage',
    ];

    public function __construct(
        private readonly CatalogAuditService $audit,
        private readonly DocumentLockService $documentLocks,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function records(User $actor): array
    {
        $this->assertManager($actor);

        return User::query()
            ->with('organization')
            ->whereHas(
                'organization',
                fn ($query) => $query->where('tenant_id', $actor->tenantId()),
            )
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => $this->present($user))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $user->loadMissing('organization');

        return [
            'id'                   => $user->uuid,
            'uuid'                 => $user->uuid,
            'systemId'             => $user->id,
            'kind'                 => CatalogKind::Users->value,
            'lastName'             => $user->last_name ?? '',
            'firstName'            => $user->first_name ?? '',
            'middleName'           => $user->middle_name ?? '',
            'fullName'             => $user->fullName(),
            'email'                => $user->email ?? '',
            'login'                => $user->login,
            'password'             => self::PASSWORD_MASK,
            'phone'                => RussianPhone::format($user->phone),
            'organizationId'       => $user->organization->uuid,
            'organizationSystemId' => $user->organization_id,
            'organization'         => $user->organization->name,
            'organizationKind'     => $user->organization->kind->value,
            'organizationType'     => $user->roleLabel(),
            'registeredAt'         => $user->registered_at?->toISOString(),
            'accessRevokedAt'      => $user->access_revoked_at?->toISOString(),
            'active'               => $user->active,
            'hasAccess'            => $user->hasPlatformAccess(),
            'accessRights'         => $user->access_rights ?? [],
            'permissions'          => ['canEdit' => true],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function create(User $actor, array $payload): array
    {
        $this->assertManager($actor);
        $payload = $this->validate($actor, $payload, true);

        return DB::transaction(function () use ($actor, $payload): array {
            $organization = $this->organization($actor, $payload['organizationId']);
            $user = new User();

            $this->fill($user, $organization, $payload, true);
            $user->save();

            $this->audit->record(
                $actor->tenantId(),
                $actor,
                CatalogKind::Users,
                $user,
                'create',
                null,
                $this->auditSnapshot($user),
            );

            return $this->present($user->refresh());
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function update(User $actor, User $user, array $payload): array
    {
        $this->assertManager($actor);

        if ($user->organization->tenant_id !== $actor->tenantId()) {
            abort(404);
        }

        $this->documentLocks->assertAvailable(
            $actor,
            'catalog_record',
            CatalogKind::Users->value . ':' . $user->uuid,
        );
        $payload = $this->validate($actor, $payload, false, $user);

        return DB::transaction(function () use ($actor, $user, $payload): array {
            $user = User::query()
                ->with('organization')
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $this->auditSnapshot($user);
            $organization = array_key_exists('organizationId', $payload)
                ? $this->organization($actor, $payload['organizationId'])
                : $user->organization;
            $passwordChanged = isset($payload['password']);

            $this->fill($user, $organization, $payload, false);
            $user->save();

            if (
                $passwordChanged
                || !$user->active
                || $user->access_revoked_at !== null
                || $before['organization_id'] !== $user->organization_id
            ) {
                $user->tokens()->delete();
                $this->documentLocks->releaseAll($user);
            }

            $after = $this->auditSnapshot($user);
            $after['password_changed'] = $passwordChanged;
            $before['password_changed'] = false;

            $this->audit->record(
                $actor->tenantId(),
                $actor,
                CatalogKind::Users,
                $user,
                'update',
                $before,
                $after,
            );

            return $this->present($user->refresh());
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validate(
        User $actor,
        array $payload,
        bool $create,
        ?User $target = null,
    ): array {
        if (
            !$create
            && in_array(
                $payload['password'] ?? null,
                ['', self::PASSWORD_MASK],
                true,
            )
        ) {
            unset($payload['password']);
        }

        foreach (['middleName', 'accessRevokedAt'] as $field) {
            if (($payload[$field] ?? null) === '') {
                $payload[$field] = null;
            }
        }

        if (array_key_exists('phone', $payload)) {
            $phone = RussianPhone::normalize((string) $payload['phone']);

            if ($phone === null) {
                Input::fail(
                    'Телефон должен быть указан в формате +7 (ххх) ххх хх хх',
                    'phone',
                );
            }

            $payload['phone'] = $phone;
        }

        $required = $create ? 'required' : 'sometimes';
        $validated = Input::validate(
            $payload,
            [
                'lastName'        => [$required, 'required', 'string', 'max:100'],
                'firstName'       => [$required, 'required', 'string', 'max:100'],
                'middleName'      => ['sometimes', 'nullable', 'string', 'max:100'],
                'email'           => [$required, 'required', 'email', 'max:255'],
                'login'           => [
                    $required,
                    'required',
                    'string',
                    'max:100',
                    Rule::unique('users', 'login')->ignore($target?->id),
                ],
                'password'        => [$required, 'string', 'min:12', 'max:72'],
                'phone'           => [$required, 'required', 'regex:/^\+7[0-9]{10}$/'],
                'organizationId'  => [$required, 'required', 'uuid'],
                'registeredAt'    => ['sometimes', 'nullable', 'date'],
                'accessRevokedAt' => ['sometimes', 'nullable', 'date'],
                'active'          => [$required, 'boolean'],
                'accessRights'    => [$required, 'array', 'list', 'max:50'],
                'accessRights.*'  => ['string', Rule::in(self::ACCESS_RIGHTS)],
            ],
            [],
            [
                'lastName'        => 'фамилию',
                'firstName'       => 'имя',
                'email'           => 'электронную почту',
                'login'           => 'логин',
                'password'        => 'пароль',
                'phone'           => 'мобильный телефон',
                'organizationId'  => 'организацию',
                'accessRights'    => 'права доступа',
            ],
        );

        if (isset($validated['organizationId'])) {
            $this->organization($actor, $validated['organizationId']);
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fill(
        User $user,
        Organization $organization,
        array $payload,
        bool $create,
    ): void {
        $map = [
            'lastName'        => 'last_name',
            'firstName'       => 'first_name',
            'middleName'      => 'middle_name',
            'email'           => 'email',
            'login'           => 'login',
            'password'        => 'password',
            'phone'           => 'phone',
            'active'          => 'active',
            'accessRights'    => 'access_rights',
        ];

        foreach ($map as $field => $attribute) {
            if (array_key_exists($field, $payload)) {
                $user->{$attribute} = $payload[$field];
            }
        }

        if (array_key_exists('registeredAt', $payload)) {
            $user->registered_at = $payload['registeredAt'] !== null
                ? CarbonImmutable::parse((string) $payload['registeredAt'])
                : now();
        } elseif ($create) {
            $user->registered_at = now();
        }

        if (array_key_exists('accessRevokedAt', $payload)) {
            $user->access_revoked_at = $payload['accessRevokedAt'] !== null
                ? CarbonImmutable::parse((string) $payload['accessRevokedAt'])
                : null;
        }

        $user->organization_id = $organization->id;
        $user->role = $organization->kind->userRole();
        $user->display_name = trim(implode(' ', array_filter([
            $user->last_name,
            $user->first_name,
            $user->middle_name,
        ]))) ?: $user->login;
    }

    private function organization(User $actor, string $uuid): Organization
    {
        return Organization::query()
            ->where('tenant_id', $actor->tenantId())
            ->where('uuid', $uuid)
            ->firstOrFail();
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

    private function assertManager(User $user): void
    {
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Справочник пользователей доступен только УК',
            );
        }
    }

    /**
     * @return array<int, string>
     */
    public static function defaultRights(
        UserRole|OrganizationKind|string $role,
    ): array
    {
        $value = $role instanceof UserRole || $role instanceof OrganizationKind
            ? $role->value
            : $role;
        $resolvedRole = UserRole::tryFrom($value) ?? UserRole::Customer;

        return match ($resolvedRole) {
            UserRole::Manager => [
                'requests.view',
                'requests.manage',
                'catalogs.manage',
                'users.manage',
            ],
            UserRole::Station => ['requests.view', 'requests.station'],
            default => [
                'requests.view',
                'requests.create',
                'catalogs.vehicles',
                'catalogs.people',
            ],
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function rightOptions(): array
    {
        $labels = [
            'requests.view'     => 'Просмотр заявок',
            'requests.create'   => 'Создание заявок',
            'requests.manage'   => 'Управление маршрутом заявок',
            'requests.station'  => 'Работа СТО с заявками',
            'catalogs.vehicles' => 'Справочник ТС Заказчиков',
            'catalogs.people'   => 'Контактные лица и водители',
            'catalogs.manage'   => 'Редактирование справочников УК',
            'users.manage'      => 'Редактирование пользователей',
        ];

        return collect(self::ACCESS_RIGHTS)
            ->map(static fn (string $right): array => [
                'value' => $right,
                'label' => $labels[$right],
            ])
            ->all();
    }
}
