<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\DocumentLock;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;

final class DocumentLockService
{
    private const RESOURCE_TYPES = ['request', 'catalog_record', 'recommendation'];

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function acquire(User $user, array $payload): array
    {
        $validated = Input::validate($payload, [
            'resourceType' => ['required', 'string', 'in:' . implode(',', self::RESOURCE_TYPES)],
            'resourceKey'  => ['required', 'string', 'max:255'],
            'label'        => ['sometimes', 'string', 'max:255'],
        ]);
        [$key, $label] = $this->resolveResource(
            $user,
            $validated['resourceType'],
            $validated['resourceKey'],
            (string) ($validated['label'] ?? ''),
        );

        return DB::transaction(function () use ($user, $validated, $key, $label): array {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                [$user->tenantId() . '|' . $validated['resourceType'] . '|' . $key],
            );

            $lock = DocumentLock::query()
                ->with('user')
                ->where('tenant_id', $user->tenantId())
                ->where('resource_type', $validated['resourceType'])
                ->where('resource_key', $key)
                ->lockForUpdate()
                ->first();

            if ($lock !== null && $lock->expires_at->isPast()) {
                $lock->delete();
                $lock = null;
            }

            if ($lock !== null && $lock->user_id !== $user->id) {
                $this->throwLocked($lock);
            }

            $expiresAt = now()->addSeconds((int) config('sto.document_lock_ttl_seconds', 120));

            if ($lock === null) {
                $lock = DocumentLock::query()->create([
                    'tenant_id'      => $user->tenantId(),
                    'user_id'        => $user->id,
                    'resource_type'  => $validated['resourceType'],
                    'resource_key'   => $key,
                    'resource_label' => $label,
                    'expires_at'     => $expiresAt,
                ]);
            } else {
                $lock->resource_label = $label;
                $lock->expires_at = $expiresAt;
                $lock->save();
            }

            return $this->present($lock->refresh()->load('user'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(User $user, string $id): array
    {
        $lock = DocumentLock::query()
            ->where('tenant_id', $user->tenantId())
            ->where('user_id', $user->id)
            ->where('uuid', $id)
            ->firstOrFail();

        $lock->expires_at = now()->addSeconds((int) config('sto.document_lock_ttl_seconds', 120));
        $lock->save();

        return $this->present($lock->refresh()->load('user'));
    }

    public function release(User $user, string $id): void
    {
        DocumentLock::query()
            ->where('tenant_id', $user->tenantId())
            ->where('user_id', $user->id)
            ->where('uuid', $id)
            ->delete();
    }

    public function releaseAll(User $user): void
    {
        DocumentLock::query()->where('user_id', $user->id)->delete();
    }

    public function releaseResource(User $user, string $type, string $key): void
    {
        DocumentLock::query()
            ->where('tenant_id', $user->tenantId())
            ->where('user_id', $user->id)
            ->where('resource_type', $type)
            ->where('resource_key', $key)
            ->delete();
    }

    public function assertAvailable(User $user, string $type, string $key): void
    {
        $lock = DocumentLock::query()
            ->with('user')
            ->where('tenant_id', $user->tenantId())
            ->where('resource_type', $type)
            ->where('resource_key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($lock !== null && $lock->user_id !== $user->id) {
            $this->throwLocked($lock);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveResource(
        User $user,
        string $type,
        string $key,
        string $label,
    ): array {
        if ($type === 'request') {
            $request = RepairRequest::resolveFor($user, $key);

            if (!$this->canMutateRequest($request, $user)) {
                throw new ApiException(
                    403,
                    'READ_ONLY',
                    'Заявка доступна в этом кабинете только для просмотра',
                );
            }

            return [$request->uuid, $request->number];
        }

        if ($type === 'recommendation') {
            $doc = \App\Models\Recommendation::forUser($user)->where('uuid', $key)->firstOrFail();
            return [$doc->uuid, $doc->number];
        }

        if ($user->role === UserRole::Station && !str_starts_with($key, 'partManufacturers:') && !str_starts_with($key, 'parts:') && !str_starts_with($key, 'suppliers:')) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Справочники недоступны кабинету СТО',
            );
        }

        if ($type === 'catalog_record' && !str_contains($key, ':')) {
            Input::fail('Укажите вид и UUID записи справочника', 'resourceKey');
        }

        return [$key, $label !== '' ? $label : $key];
    }

    private function canMutateRequest(RepairRequest $request, User $user): bool
    {
        $permissions = Workflow::permissions($request, $user);

        if (($permissions['editableFields'] ?? []) !== []) {
            return true;
        }

        foreach ($permissions as $key => $value) {
            if ($key !== 'editableFields' && $value === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DocumentLock $lock): array
    {
        return [
            'id'           => $lock->uuid,
            'resourceType' => $lock->resource_type,
            'resourceKey'  => $lock->resource_key,
            'label'        => $lock->resource_label,
            'lockedBy'     => $lock->user->display_name,
            'lockedLogin'  => $lock->user->login,
            'expiresAt'    => $lock->expires_at->toISOString(),
        ];
    }

    private function throwLocked(DocumentLock $lock): never
    {
        $name = $lock->user->display_name ?: $lock->user->login;

        throw new ApiException(
            423,
            'DOCUMENT_LOCKED',
            'Документ уже открыт пользователем ' . $name
                . ' (личный кабинет ' . $lock->user->login . ')',
            [
                'lockedBy'    => $name,
                'lockedLogin' => $lock->user->login,
                'expiresAt'   => $lock->expires_at->toISOString(),
            ],
        );
    }
}
