<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\VehicleDriveType;
use App\Models\VehicleEngineVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleTransmissionType;
use App\Support\DomainInput as Input;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class VehicleReferenceService
{
    public const TRANSMISSIONS = ['МКПП', 'РКПП', 'АКПП', 'CVT', 'электро'];

    public const DRIVE_TYPES = ['передний', 'задний', 'полный'];

    public const ENGINE_TYPES = ['Бензин', 'дизель', 'гибрид', 'электро', 'газ', 'газ/бензин', 'водородный'];

    public function __construct(
        private readonly CatalogAuditService $audit,
    ) {
    }

    /** Последовательные варианты из фактически существующих записей каталога. */
    public function selectionOptions(User $user, string $makeId, string $step, array $filters): array
    {
        $this->assertReader($user);
        $keys = ['model', 'generation', 'engineType', 'engineIndex', 'horsepower',
            'engineVolumeLiters', 'transmission', 'driveType'];
        $position = array_search($step, $keys, true);
        if ($position === false || array_diff(array_keys($filters), array_slice($keys, 0, $position + 1))) {
            Input::fail('Неверная последовательность технических признаков');
        }
        $make = VehicleMake::query()->where('uuid', $makeId)->where('active', true)->firstOrFail();
        $records = VehicleModel::query()->with('make')->where('vehicle_make_id', $make->id)
            ->where('active', true)->orderBy('id')->get()
            ->map(fn (VehicleModel $model): array => $this->presentModel($model));
        $value = static function (array $record, string $key): string {
            if ($key === 'generation') {
                return json_encode([$record['bodyCode'], $record['modelYearFrom'], $record['modelYearTo']], JSON_THROW_ON_ERROR);
            }
            if ($key === 'engineType' && ($record['sourceEngineType'] ?? '') !== '') {
                return $record['sourceEngineType'];
            }
            return $record[$key] === null || $record[$key] === '' ? '__unknown__' : (string) $record[$key];
        };
        foreach ($filters as $key => $filter) {
            $records = $records->filter(fn (array $record): bool => $value($record, $key) === $filter);
        }
        $options = $records->map(function (array $record) use ($step, $value): array {
            $key = $value($record, $step);
            $label = $step === 'generation'
                ? implode(' · ', array_filter([$record['bodyCode'], $record['modelYearFrom'], $record['modelYearTo']], fn ($v) => $v !== null && $v !== ''))
                : ($key === '__unknown__' ? 'Не указано в источнике' : $key);
            return ['value' => $key, 'label' => $label ?: 'Поколение не указано'];
        })->unique('value')->sortBy('label')->values()->all();
        return ['options' => $options, 'variant' => $step === 'driveType' && $records->count() === 1 ? $records->first() : null];
    }

    public function makes(User $user, string $search, int $limit): array
    {
        $this->assertReader($user);
        $prefix = self::normalizeName($search);

        return VehicleMake::query()
            ->where('active', true)
            ->when(
                $prefix !== '',
                fn ($query) => ReferenceSearch::apply($query, $prefix, ['normalized_name']),
            )
            ->orderBy('normalized_name')
            ->limit($limit)
            ->get()
            ->map(fn (VehicleMake $make): array => $this->presentMake($make))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function models(
        User $user,
        string $makeId,
        string $search,
        int $limit,
        bool $uniqueNames = false,
    ): array {
        $this->assertReader($user);
        $make = VehicleMake::query()->where('uuid', $makeId)->firstOrFail();
        $prefix = self::normalizeName($search);

        return VehicleModel::query()
            ->with('make')
            ->when($uniqueNames, fn ($query) => $query->selectRaw(
                'DISTINCT ON (vehicle_models.normalized_name) vehicle_models.*',
            ))
            ->where('vehicle_make_id', $make->id)
            ->where('active', true)
            ->when(
                $prefix !== '',
                fn ($query) => ReferenceSearch::apply($query, $prefix, ['normalized_name']),
            )
            ->orderBy('normalized_name')
            ->orderBy('model_year_from')
            ->orderBy('engine_index')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (VehicleModel $model): array => $this->presentModel($model))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function generations(
        User $user,
        string $modelId,
        string $search,
        int $limit,
    ): array {
        $this->assertReader($user);
        $model = VehicleModel::query()
            ->where('uuid', $modelId)
            ->where('active', true)
            ->firstOrFail();
        $prefix = self::normalizeName($search);

        return VehicleGeneration::query()
            ->selectRaw('DISTINCT ON (normalized_name, year_from, year_to) vehicle_generations.*')
            ->whereIn('vehicle_model_id', VehicleReferenceIdentity::modelIds($model))
            ->where('active', true)
            ->when(
                $prefix !== '',
                fn ($query) => ReferenceSearch::apply($query, $prefix, ['normalized_name', 'year_from', 'year_to']),
            )
            ->orderBy('normalized_name')
            ->orderBy('year_from')
            ->orderBy('year_to')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (VehicleGeneration $generation): array => [
                'id'       => $generation->uuid,
                'uuid'     => $generation->uuid,
                'modelId'  => $modelId,
                'name'     => $generation->name,
                'yearFrom' => $generation->year_from,
                'yearTo'   => $generation->year_to,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function engines(
        User $user,
        string $modelId,
        ?string $generationId,
        string $search,
        int $limit,
    ): array {
        $this->assertReader($user);
        $model = VehicleModel::query()
            ->where('uuid', $modelId)
            ->where('active', true)
            ->firstOrFail();
        $generation = $generationId === null
            ? null
            : VehicleGeneration::query()
                ->where('uuid', $generationId)
                ->whereIn('vehicle_model_id', VehicleReferenceIdentity::modelIds($model))
                ->where('active', true)
                ->firstOrFail();
        $prefix = self::normalizeName($search);

        return VehicleEngineVariant::query()
            ->selectRaw('DISTINCT ON (code, engine_type, power_hp) vehicle_engine_variants.*')
            ->with('generation')
            ->whereIn('vehicle_model_id', VehicleReferenceIdentity::modelIds($model))
            ->where('active', true)
            ->when(
                $generation !== null,
                fn ($query) => $query->where(function ($query) use ($generation): void {
                    $query->whereNull('vehicle_generation_id')
                        ->orWhereIn('vehicle_generation_id', VehicleReferenceIdentity::generationIds($generation));
                }),
            )
            ->when(
                $prefix !== '',
                fn ($query) => ReferenceSearch::apply($query, $prefix, ['code', 'engine_type', 'power_hp']),
            )
            ->orderBy('code')
            ->orderBy('engine_type')
            ->orderBy('power_hp')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (VehicleEngineVariant $engine): array => [
                'id'           => $engine->uuid,
                'uuid'         => $engine->uuid,
                'modelId'      => $modelId,
                'generationId' => $engine->generation?->uuid ?? '',
                'name'         => $engine->code,
                'engineType'   => $engine->engine_type,
                'powerHp'      => $engine->power_hp !== null
                    ? (float) $engine->power_hp
                    : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transmissions(User $user, string $search, int $limit): array
    {
        $this->assertReader($user);

        return $this->vehicleAttributeOptions(
            VehicleTransmissionType::query(),
            $search,
            $limit,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function drives(User $user, string $search, int $limit): array
    {
        $this->assertReader($user);

        return $this->vehicleAttributeOptions(
            VehicleDriveType::query(),
            $search,
            $limit,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload, bool $rejectDuplicate = false): array
    {
        if (!in_array($user->role, [UserRole::Customer, UserRole::Manager], true)) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Добавление марки и модели недоступно вашей роли',
            );
        }

        $payload = array_replace([
            'modelYearFrom' => null,
            'modelYearTo'   => null,
            'engineIndex'   => '',
            'bodyCode'      => '',
            'horsepower'    => null,
            'engineVolumeLiters' => null,
            'transmission'  => null,
            'driveType'     => null,
            'engineType'    => null,
        ], $payload);

        foreach ([
            'engineVolumeLiters',
            'modelYearFrom',
            'modelYearTo',
            'horsepower',
            'transmission',
            'driveType',
            'engineType',
        ] as $field) {
            if (($payload[$field] ?? null) === '') {
                $payload[$field] = null;
            }
        }

        $payload = Input::validate($payload, [
            'make'          => ['required', 'string', 'max:255'],
            'model'         => ['required', 'string', 'max:255'],
            'modelYearFrom' => ['nullable', 'integer', 'between:1900,2100'],
            'modelYearTo'   => ['nullable', 'integer', 'between:1900,2100'],
            'engineIndex'   => ['present', 'string', 'max:100'],
            'bodyCode'      => ['present', 'string', 'max:150'],
            'engineVolumeLiters' => ['nullable', 'numeric', 'min:0.001', 'max:100', Input::numeric(3)],
            'horsepower'    => [
                'nullable',
                'numeric',
                'between:0.1,3000',
                Input::numeric(1),
            ],
            'transmission' => [
                'nullable',
                'string',
                'in:' . implode(',', self::TRANSMISSIONS),
            ],
            'driveType' => [
                'nullable',
                'string',
                'in:' . implode(',', self::DRIVE_TYPES),
            ],
            'engineType' => [
                'nullable',
                'string',
                'in:' . implode(',', self::ENGINE_TYPES),
            ],
        ]);

        if (
            $payload['modelYearFrom'] !== null
            && $payload['modelYearTo'] !== null
            && $payload['modelYearTo'] < $payload['modelYearFrom']
        ) {
            Input::fail(
                'Модельный год до не может быть меньше модельного года от',
                'modelYearTo',
            );
        }

        return DB::transaction(function () use ($user, $payload, $rejectDuplicate): array {
            $makeName = self::cleanName((string) $payload['make']);
            $modelName = self::cleanName((string) $payload['model']);

            if ($makeName === '' || $modelName === '') {
                Input::fail('Укажите марку и модель');
            }

            $make = VehicleMake::query()->firstOrCreate(
                ['normalized_name' => self::normalizeName($makeName)],
                ['name' => $makeName, 'active' => true],
            );

            if (!$make->active) {
                $make->forceFill(['active' => true])->save();
            }

            $attributes = [
                'vehicle_make_id' => $make->id,
                'name'            => $modelName,
                'normalized_name' => self::normalizeName($modelName),
                'model_year_from' => $payload['modelYearFrom'],
                'model_year_to'   => $payload['modelYearTo'],
                'engine_index'    => trim((string) $payload['engineIndex']),
                'body_code'       => trim((string) $payload['bodyCode']),
                'horsepower'      => $payload['horsepower'],
                'engine_volume_liters' => $payload['engineVolumeLiters'],
                'transmission'    => $payload['transmission'],
                'source_transmission' => (string) ($payload['sourceTransmission'] ?? ''),
                'drive_type'      => $payload['driveType'],
                'source_drive_type' => (string) ($payload['sourceDriveType'] ?? ''),
                'engine_type'     => $payload['engineType'],
                'source_engine_type' => (string) ($payload['sourceEngineType'] ?? ''),
            ];
            $fingerprint = self::fingerprint($makeName, $attributes);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vehicle-reference:' . $fingerprint]);
            $model = VehicleModel::query()->where('fingerprint', $fingerprint)->first();
            if ($rejectDuplicate && $model !== null) {
                throw new ApiException(409, 'VEHICLE_VARIANT_DUPLICATE', 'ТС с таким набором параметров уже существует в справочнике');
            }

            if ($model === null) {
                $model = VehicleModel::query()->create(array_merge(
                    $attributes,
                    ['fingerprint' => $fingerprint],
                ));

                $this->audit->record(
                    $user->tenantId(),
                    $user,
                    CatalogKind::VehicleModels,
                    $model,
                    'create',
                    null,
                    array_merge($model->attributesToArray(), ['make' => $make->name]),
                );
            } elseif (!$model->active) {
                $model->forceFill(['active' => true])->save();
            }

            $this->syncTypedApplicability($model);

            return $this->presentModel($model->load('make'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentMake(VehicleMake $make): array
    {
        return [
            'id'   => $make->uuid,
            'uuid' => $make->uuid,
            'name' => $make->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentModel(VehicleModel $model): array
    {
        $model->loadMissing('make');

        return [
            'id'            => $model->uuid,
            'uuid'          => $model->uuid,
            'makeId'        => $model->make->uuid,
            'make'          => $model->make->name,
            'model'         => $model->name,
            'modelYearFrom' => $model->model_year_from,
            'engineVolumeLiters' => $model->engine_volume_liters !== null
                ? (float) $model->engine_volume_liters : null,
            'modelYearTo'   => $model->model_year_to,
            'engineIndex'   => $model->engine_index,
            'bodyCode'      => $model->body_code,
            'horsepower'    => $model->horsepower !== null
                ? (float) $model->horsepower
                : null,
            'transmission' => $model->transmission,
            'sourceTransmission' => $model->source_transmission,
            'driveType'    => $model->drive_type,
            'sourceDriveType' => $model->source_drive_type,
            'engineType'   => $model->engine_type,
            'sourceEngineType' => $model->source_engine_type,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fingerprint(string $make, array $attributes): string
    {
        $values = [
            self::normalizeName($make),
            self::normalizeName((string) ($attributes['name'] ?? '')),
            $attributes['model_year_from'] ?? null,
            $attributes['model_year_to'] ?? null,
            mb_strtoupper(trim((string) ($attributes['engine_index'] ?? ''))),
            mb_strtoupper(trim((string) ($attributes['body_code'] ?? ''))),
            isset($attributes['horsepower'])
                ? number_format((float) $attributes['horsepower'], 1, '.', '')
                : null,
            $attributes['transmission'] ?? null,
            ($attributes['transmission'] ?? null) === null
                ? trim((string) ($attributes['source_transmission'] ?? '')) : null,
            $attributes['drive_type'] ?? null,
            ($attributes['drive_type'] ?? null) === null
                ? trim((string) ($attributes['source_drive_type'] ?? '')) : null,
            $attributes['engine_type'] ?? null,
            ($attributes['engine_type'] ?? null) === null
                ? trim((string) ($attributes['source_engine_type'] ?? '')) : null,
        ];

        if (isset($attributes['engine_volume_liters'])) {
            $values[] = number_format((float) $attributes['engine_volume_liters'], 3, '.', '');
        }

        return hash(
            'sha256',
            json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public static function cleanName(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public static function normalizeName(string $value): string
    {
        return (string) mb_strtolower(self::cleanName($value));
    }

    private function syncTypedApplicability(VehicleModel $model): void
    {
        $bodyCode = trim((string) $model->body_code);
        $generation = null;

        if ($bodyCode !== '') {
            $generation = VehicleGeneration::query()->updateOrCreate(
                [
                    'vehicle_model_id' => $model->id,
                    'normalized_name' => self::normalizeName($bodyCode),
                ],
                [
                    'name' => $bodyCode,
                    'year_from' => $model->model_year_from,
                    'year_to' => $model->model_year_to,
                    'active' => true,
                ],
            );
        }

        $engineIndex = trim((string) $model->engine_index);
        if ($engineIndex === '') {
            return;
        }

        VehicleEngineVariant::query()->updateOrCreate(
            ['fingerprint' => $model->fingerprint],
            [
                'vehicle_model_id' => $model->id,
                'vehicle_generation_id' => $generation?->id,
                'code' => $engineIndex,
                'engine_type' => $model->engine_type,
                'power_hp' => $model->horsepower,
                'active' => true,
            ],
        );
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{
     *     records: array<int, array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     perPage: int
     * }
     */
    public function catalog(
        User $user,
        array $filters,
        int $page,
        int $perPage,
    ): array {
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Внутренний справочник марок и моделей доступен только УК',
            );
        }

        $query = VehicleModel::query()
            ->with('make')
            ->where('vehicle_models.active', true)
            ->whereHas('make', fn ($make) => $make->where('active', true));

        foreach ($filters as $field => $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            if ($field === 'make') {
                $query->whereHas('make', fn ($make) => ReferenceSearch::apply($make, $value, ['name']));

                continue;
            }

            $attribute = match ($field) {
                'model'        => 'vehicle_models.name',
                'bodyCode'     => 'vehicle_models.body_code',
                'engineIndex'  => 'vehicle_models.engine_index',
                'transmission' => 'vehicle_models.transmission',
                'driveType'    => 'vehicle_models.drive_type',
                'engineType'   => 'vehicle_models.engine_type',
                default        => null,
            };

            if ($attribute !== null) {
                $sourceAttribute = match ($field) {
                    'transmission' => 'vehicle_models.source_transmission',
                    'driveType' => 'vehicle_models.source_drive_type',
                    'engineType' => 'vehicle_models.source_engine_type',
                    default => null,
                };
                ReferenceSearch::apply($query, $value, array_filter([$attribute, $sourceAttribute]));

                continue;
            }

            $numericAttribute = match ($field) {
                'modelYearFrom' => 'vehicle_models.model_year_from',
                'modelYearTo'   => 'vehicle_models.model_year_to',
                'horsepower'    => 'vehicle_models.horsepower',
                'engineVolumeLiters' => 'vehicle_models.engine_volume_liters',
                default         => null,
            };

            if ($numericAttribute !== null) {
                ReferenceSearch::apply($query, $value, [$numericAttribute]);
            }
        }

        $total = (clone $query)->count();
        $records = $query
            ->join(
                'vehicle_makes',
                'vehicle_makes.id',
                '=',
                'vehicle_models.vehicle_make_id',
            )
            ->orderBy('vehicle_makes.normalized_name')
            ->orderBy('vehicle_models.normalized_name')
            ->orderBy('vehicle_models.model_year_from')
            ->select('vehicle_models.*')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (VehicleModel $model): array => array_merge(
                $this->presentModel($model),
                ['kind' => CatalogKind::VehicleModels->value],
            ))
            ->all();

        return compact('records', 'total', 'page', 'perPage');
    }

    private function assertReader(User $user): void
    {
        if (!in_array($user->role, [UserRole::Customer, UserRole::Manager], true)) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Справочник марок и моделей недоступен вашей роли',
            );
        }
    }

    /**
     * @param Builder<VehicleTransmissionType|VehicleDriveType> $query
     *
     * @return array<int, array<string, mixed>>
     */
    private function vehicleAttributeOptions(
        Builder $query,
        string $search,
        int $limit,
    ): array {
        $prefix = self::normalizeName($search);

        return $query
            ->where('active', true)
            ->when(
                $prefix !== '',
                fn ($query) => ReferenceSearch::apply($query, $prefix, ['name', 'code']),
            )
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(static fn (Model $record): array => [
                'id'   => $record->uuid,
                'uuid' => $record->uuid,
                'code' => $record->code,
                'name' => $record->name,
            ])
            ->all();
    }
}
