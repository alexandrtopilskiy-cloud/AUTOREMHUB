<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\UserDto;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\PartManufacturer;
use App\Models\RequestNotification;
use App\Models\User;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;

final class PartManufacturerService
{
    public function __construct(private readonly CatalogAuditService $audit)
    {
    }

    public function write(User $user, ?PartManufacturer $record, array $payload): array
    {
        $dto = UserDto::fromUserModel($user);
        $this->authorize($user);

        if ($record) {
            app(DocumentLockService::class)->assertAvailable($dto, 'catalog_record', 'partManufacturers:' . $record->uuid);
        }
        Input::validate($payload, [
            'name' => ['required', 'string', 'max:200'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $name = preg_replace('/\s+/u', ' ', trim($payload['name']));
        if ($name === '') {
            Input::fail('Укажите производителя', 'name');
        }

        return DB::transaction(function () use ($dto, $record, $payload, $name): array {
            DB::table('tenants')
                ->where('id', $dto->tenantId)
                ->lockForUpdate()
                ->first();
            if ($record) {
                $record = PartManufacturer::query()
                    ->whereKey($record->id)
                    ->where('tenant_id', $dto->tenantId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($record->proposed_name !== null || $record->status === 'pending') {
                    throw new ApiException(
                        status: 409,
                        errorCode: 'PENDING_APPROVAL',
                        message: 'Изменения производителя уже ожидают решения УК',
                    );
                }
            }

            $normalized = mb_strtolower($name);
            $duplicate = PartManufacturer::query()->where('tenant_id', $dto->tenantId)->where(function ($query) use ($normalized): void {
                $query->where('normalized_name', $normalized)->orWhereRaw('LOWER(proposed_name) = ?', [$normalized]);
            })->when($record, fn ($query) => $query->where('id', '!=', $record->id))->exists();
            if ($duplicate) {
                Input::fail('Производитель с таким названием уже существует', 'name');
            }

            $before = $record?->attributesToArray();
            $record ??= new PartManufacturer([
                'tenant_id' => $dto->tenantId,
                'name' => $name,
                'normalized_name' => $normalized,
            ]);

            if ($dto->role === UserRole::Manager) {
                $record->fill([
                    'name' => $name,
                    'normalized_name' => $normalized,
                    'active' => $payload['active'] ?? true,
                    'status' => 'approved',
                ]);
            } else {
                $record->fill([
                    'status' => $record->exists ? $record->status : 'pending',
                    'proposed_name' => $name,
                    'proposed_active' => $payload['active'] ?? true,
                    'requested_by' => $dto->userId,
                ]);
            }

            $record->revision++;
            $record->save();

            $this->audit->record(
                dto: $dto,
                kind: 'partManufacturers',
                record: $record,
                action: $dto->role === UserRole::Manager ? 'save' : 'propose',
                before: $before,
                after: $record->attributesToArray(),
            );

            if ($dto->role === UserRole::Station) {
                $managers = User::query()
                    ->where('role', 'manager')
                    ->whereHas('organization', fn ($q) => $q->where('tenant_id', $dto->tenantId))
                    ->get();
                foreach ($managers as $manager) {
                    RequestNotification::query()->create([
                        'user_id' => $manager->id,
                        'part_manufacturer_id' => $record->id,
                        'kind' => 'manufacturer_approval',
                        'title' => 'Согласовать производителя ЗЧ',
                        'message' => $name,
                    ]);
                }
            }
            return $this->present($record, $dto);
        });
    }

    public function review(User $user, string $id, array $payload): array
    {
        $dto = UserDto::fromUserModel($user);

        if ($dto->role !== UserRole::Manager) {
            throw new ApiException(
                status: 403,
                errorCode: 'FORBIDDEN',
                message: 'Изменения производителей согласует УК',
            );
        }
        app(DocumentLockService::class)->assertAvailable($dto, 'catalog_record', 'partManufacturers:' . $id);
        Input::validate($payload, [
            'decision' => ['required', 'in:approved,rejected'],
            'revision' => ['required', 'integer'],
        ]);

        return DB::transaction(function () use ($dto, $id, $payload): array {
            DB::table('tenants')
                ->where('id', $dto->tenantId)
                ->lockForUpdate()
                ->first();
            $record = PartManufacturer::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('uuid', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->revision !== $payload['revision'] || $record->proposed_name === null) {
                throw new ApiException(status: 409,
                    errorCode: 'STALE_APPROVAL',
                    message: 'Предложение уже изменено или рассмотрено',
                );
            }

            $before = $record->attributesToArray();
            $approved = $payload['decision'] === 'approved';
            if ($approved) {
                $record->name = $record->proposed_name;
                $record->normalized_name = mb_strtolower($record->name);
                $record->active = $record->proposed_active;
                $record->status = 'approved';
            } elseif ($record->status === 'pending') {
                $record->status = 'rejected';
                $record->active = false;
            }

            $actor = $record->requested_by;
            $record->proposed_name = null;
            $record->proposed_active = null;
            $record->revision++;
            $record->save();
            RequestNotification::query()->where('part_manufacturer_id', $record->id)->delete();

            if ($actor) {
                RequestNotification::query()->create([
                    'user_id' => $actor,
                    'part_manufacturer_id' => $record->id,
                    'kind' => 'manufacturer_reviewed',
                    'title' => $approved ? 'Производитель согласован УК' : 'Производитель отклонён УК',
                    'message' => $record->name,
                ]);
            }

            $this->audit->record($dto, 'partManufacturers', $record, $payload['decision'], $before, $record->attributesToArray());
            return $this->present($record, $dto);
        });
    }

    public function present(PartManufacturer $record, UserDto $dto): array
    {
        return [
            'id' => $record->uuid,
            'kind' => 'partManufacturers',
            'name' => $record->name,
            'active' => $record->active,
            'status' => $record->status,
            'proposedName' => $record->proposed_name,
            'proposedActive' => $record->proposed_active,
            'revision' => $record->revision,
            'permissions' => [
                'canEdit' => in_array($dto->role, [UserRole::Manager, UserRole::Station], true) && $record->proposed_name === null,
                'canReview' => $dto->role === UserRole::Manager && $record->proposed_name !== null
            ]
        ];
    }

    private function authorize(User $user): void
    {
        if (!in_array($user->role, [UserRole::Manager, UserRole::Station], true)) {
            throw new ApiException(403, 'FORBIDDEN', 'Производители доступны УК и СТО');
        }
    }
}
