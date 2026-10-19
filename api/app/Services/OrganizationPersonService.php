<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\OrganizationKind;
use App\Enums\PersonKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\User;
use App\Models\VehicleContact;
use App\Support\DomainInput as Input;
use App\Support\RussianPhone;
use Illuminate\Support\Facades\DB;

final class OrganizationPersonService
{
    public function __construct(
        private readonly CatalogAuditService $audit,
        private readonly DocumentLockService $documentLocks,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function records(User $user): array
    {
        return OrganizationPerson::forUser($user)
            ->with('organization')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (OrganizationPerson $person): array => $this->present($person, $user))
            ->all();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload): array
    {
        $this->assertEditor($user);

        $payload = $this->validatePayload($user, $payload, true);

        return DB::transaction(function () use ($user, $payload): array {
            $organization = $this->organizationForPayload($user, $payload);

            $existing = OrganizationPerson::query()
                ->where('organization_id', $organization->id)
                ->where('kind', $payload['kind'])
                ->where('phone', $payload['phone'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->active) {
                Input::fail(
                    'Такой телефон уже есть в справочнике организации',
                    'phone',
                );
            }

            if ($existing !== null) {
                $before = $existing->attributesToArray();
                $existing->fill([
                    'last_name'   => trim($payload['lastName']),
                    'first_name'  => trim($payload['firstName']),
                    'middle_name' => trim($payload['middleName'] ?? '') ?: null,
                    'active'      => true,
                ])->save();

                $this->audit->record(
                    $user->tenantId(),
                    $user,
                    $this->catalogKind($existing->kind),
                    $existing,
                    'restore',
                    $before,
                    $existing->attributesToArray(),
                );

                return $this->present($existing->refresh(), $user);
            }

            $person = OrganizationPerson::query()->create([
                'tenant_id'       => $user->tenantId(),
                'organization_id' => $organization->id,
                'kind'            => $payload['kind'],
                'last_name'       => trim($payload['lastName']),
                'first_name'      => trim($payload['firstName']),
                'middle_name'     => trim($payload['middleName'] ?? '') ?: null,
                'phone'           => $payload['phone'],
                'active'          => true,
            ]);

            $this->audit->record(
                $user->tenantId(),
                $user,
                $this->catalogKind($person->kind),
                $person,
                'create',
                null,
                $person->attributesToArray(),
            );

            return $this->present($person->refresh(), $user);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function update(User $user, string $id, array $payload): array
    {
        $this->assertEditor($user);
        $person = $this->resolve($user, $id);
        $this->documentLocks->assertAvailable(
            $user,
            'catalog_record',
            $this->catalogKind($person->kind)->value . ':' . $person->uuid,
        );
        $payload = $this->validatePayload($user, $payload, false);

        return DB::transaction(function () use ($user, $person, $payload): array {
            $person = OrganizationPerson::query()
                ->whereKey($person->id)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $person->attributesToArray();
            $phone = $payload['phone'] ?? $person->phone;

            $this->assertUniquePhone(
                $person->organization_id,
                $person->kind,
                $phone,
                $person->id,
            );
            $this->assertVehiclePhoneAvailable($person, $phone);

            $map = [
                'lastName'   => 'last_name',
                'firstName'  => 'first_name',
                'middleName' => 'middle_name',
                'phone'      => 'phone',
            ];

            foreach ($map as $field => $attribute) {
                if (array_key_exists($field, $payload)) {
                    $value = is_string($payload[$field])
                        ? trim($payload[$field])
                        : $payload[$field];
                    $person->{$attribute} = $attribute === 'middle_name'
                        ? ($value ?: null)
                        : $value;
                }
            }

            $person->save();

            VehicleContact::query()
                ->where('organization_person_id', $person->id)
                ->update([
                    'last_name'   => $person->last_name,
                    'first_name'  => $person->first_name,
                    'middle_name' => $person->middle_name,
                    'phone'       => $person->phone,
                    'updated_at'  => now(),
                ]);

            $this->audit->record(
                $user->tenantId(),
                $user,
                $this->catalogKind($person->kind),
                $person,
                'update',
                $before,
                $person->attributesToArray(),
            );

            return $this->present($person->refresh(), $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $user, string $id): array
    {
        $this->assertEditor($user);
        $person = $this->resolve($user, $id);
        $this->documentLocks->assertAvailable(
            $user,
            'catalog_record',
            $this->catalogKind($person->kind)->value . ':' . $person->uuid,
        );

        return DB::transaction(function () use ($user, $person): array {
            $person = OrganizationPerson::query()
                ->whereKey($person->id)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $person->attributesToArray();
            $person->active = false;
            $person->save();

            VehicleContact::query()
                ->where('organization_person_id', $person->id)
                ->update(['active' => false, 'updated_at' => now()]);

            $this->audit->record(
                $user->tenantId(),
                $user,
                $this->catalogKind($person->kind),
                $person,
                'delete',
                $before,
                $person->attributesToArray(),
            );

            return [
                'id'      => $person->uuid,
                'deleted' => true,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(OrganizationPerson $person, User $user): array
    {
        $person->loadMissing('organization');

        return [
            'id'             => $person->uuid,
            'uuid'           => $person->uuid,
            'systemId'       => $person->id,
            'kind'           => $this->catalogKind($person->kind)->value,
            'personKind'     => $person->kind->value,
            'lastName'       => $person->last_name,
            'firstName'      => $person->first_name,
            'middleName'     => $person->middle_name ?? '',
            'fullName'       => $person->fullName(),
            'organizationId' => $person->organization->uuid,
            'company'        => $person->organization->name,
            'phone'          => RussianPhone::format($person->phone),
            'phoneRaw'       => $person->phone,
            'active'         => $person->active,
            'permissions'    => [
                'canEdit' => in_array(
                    $user->role,
                    [UserRole::Customer, UserRole::Manager],
                    true,
                ),
                'canDelete' => in_array(
                    $user->role,
                    [UserRole::Customer, UserRole::Manager],
                    true,
                ),
            ],
        ];
    }

    private function assertEditor(User $user): void
    {
        if (!in_array($user->role, [UserRole::Customer, UserRole::Manager], true)) {
            throw new ApiException(403, 'FORBIDDEN', 'Справочник лиц недоступен');
        }
    }

    private function resolve(User $user, string $id): OrganizationPerson
    {
        return OrganizationPerson::forUser($user)
            ->where('uuid', $id)
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validatePayload(User $user, array $payload, bool $create): array
    {
        if (isset($payload['phone'])) {
            $phone = RussianPhone::normalize((string) $payload['phone']);

            if ($phone === null) {
                Input::fail(
                    'Телефон должен быть в формате +7 (ххх) ххх хх хх',
                    'phone',
                );
            }

            $payload['phone'] = $phone;
        }

        if ($user->role === UserRole::Customer) {
            unset($payload['organizationId']);
        }

        return Input::validate($payload, [
            'kind'           => [
                $create ? 'required' : 'sometimes',
                'string',
                'in:' . implode(',', PersonKind::values()),
            ],
            'organizationId' => $user->role === UserRole::Manager
                ? [$create ? 'required' : 'sometimes', 'uuid']
                : ['sometimes'],
            'lastName'       => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:100'],
            'firstName'      => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:100'],
            'middleName'     => ['sometimes', 'string', 'max:100'],
            'phone'          => [$create ? 'required' : 'sometimes', 'string', 'regex:/^\\+7[0-9]{10}$/'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function organizationForPayload(User $user, array $payload): Organization
    {
        if ($user->role === UserRole::Customer) {
            return $user->organization;
        }

        return Organization::query()
            ->where('tenant_id', $user->tenantId())
            ->where('kind', OrganizationKind::Customer->value)
            ->where('uuid', $payload['organizationId'] ?? '')
            ->firstOrFail();
    }

    private function assertUniquePhone(
        int $organizationId,
        PersonKind $kind,
        string $phone,
        ?int $exceptId = null,
    ): void {
        $exists = OrganizationPerson::query()
            ->where('organization_id', $organizationId)
            ->where('kind', $kind->value)
            ->where('phone', $phone)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            Input::fail('Такой телефон уже есть в справочнике организации', 'phone');
        }
    }

    private function assertVehiclePhoneAvailable(
        OrganizationPerson $person,
        string $phone,
    ): void {
        $vehicleIds = VehicleContact::query()
            ->where('organization_person_id', $person->id)
            ->pluck('vehicle_id');

        $conflict = VehicleContact::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('organization_person_id', '!=', $person->id)
            ->where('phone', $phone)
            ->exists();

        if ($conflict) {
            Input::fail(
                'Телефон уже используется другим лицом в одном из автомобилей',
                'phone',
            );
        }
    }

    private function catalogKind(PersonKind $kind): CatalogKind
    {
        return $kind->catalogKind();
    }
}
