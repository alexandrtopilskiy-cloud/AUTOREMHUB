<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\TimeNorm;
use App\Models\TimeNormApplicability;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkCatalogImport;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;

final class WorkCatalogImportService
{
    public function __construct(
        private readonly WorkCatalogWorkbookReader $reader,
        private readonly WorkCatalogValidator $validator,
    ) {
    }

    /** @return array<string, mixed> */
    public function preview(User $user, string $path, string $originalName): array
    {
        $this->assertManager($user);
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new ApiException(
                422,
                'INVALID_XLSX',
                'Не удалось рассчитать контрольную сумму файла',
            );
        }

        $validated = $this->validator->validate($user, $this->reader->read($path));
        $version = 'catalog-' . substr($checksum, 0, 16);

        return [
            'fileName' => $originalName,
            'checksum' => $checksum,
            'catalogVersion' => $version,
            'canApply' => $validated['errors'] === [],
            'summary' => $validated['summary'],
            'errors' => $validated['errors'],
            'warnings' => $this->warnings($validated),
            'works' => $validated['works'],
            'norms' => array_map(
                fn (array $norm): array => $this->presentNorm($norm),
                $validated['norms'],
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function apply(
        User $user,
        string $path,
        string $originalName,
        string $expectedChecksum,
    ): array {
        $preview = $this->preview($user, $path, $originalName);

        if (!hash_equals($preview['checksum'], $expectedChecksum)) {
            throw new ApiException(
                409,
                'IMPORT_FILE_CHANGED',
                'Файл изменился после проверки. '
                . 'Выполните сверку повторно',
            );
        }

        if (!$preview['canApply']) {
            return $preview;
        }

        return $this->replaceCatalog($user, $preview);
    }

    /**
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed>
     */
    private function replaceCatalog(User $user, array $preview): array
    {
        return DB::transaction(function () use ($user, $preview): array {
            Tenant::query()
                ->whereKey($user->tenantId())
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyApplied = WorkCatalogImport::query()
                ->where('tenant_id', $user->tenantId())
                ->where('checksum', $preview['checksum'])
                ->where('status', 'applied')
                ->lockForUpdate()
                ->first();

            if ($alreadyApplied !== null) {
                throw new ApiException(
                    409,
                    'CATALOG_ALREADY_APPLIED',
                    'Эта редакция справочника уже применена',
                );
            }

            $batch = WorkCatalogImport::query()->create([
                'tenant_id' => $user->tenantId(),
                'user_id' => $user->id,
                'original_name' => $preview['fileName'],
                'checksum' => $preview['checksum'],
                'catalog_version' => $preview['catalogVersion'],
                'status' => 'validating',
                'summary' => $preview['summary'],
                'errors' => [],
            ]);

            $activeWorkIds = WorkType::query()
                ->where('tenant_id', $user->tenantId())
                ->where('active', true)
                ->lockForUpdate()
                ->pluck('id');
            $archivedNorms = TimeNorm::query()
                ->where('tenant_id', $user->tenantId())
                ->where('active', true)
                ->update(['active' => false, 'archived_at' => now()]);
            $archivedWorks = WorkType::query()
                ->whereIn('id', $activeWorkIds)
                ->update(['active' => false, 'archived_at' => now()]);

            $workModels = [];
            foreach ($preview['works'] as $work) {
                $workModels[$work['code']] = WorkType::query()->create([
                    'tenant_id' => $user->tenantId(),
                    'code' => $work['code'],
                    'group_code' => $work['group_code'],
                    'name' => $work['name'],
                    'group_name' => $work['group_name'],
                    'catalog_version' => $preview['catalogVersion'],
                    'source_row' => $work['source_row'],
                    'source_name' => $work['source_name'],
                    'catalog_import_id' => $batch->id,
                    'is_maintenance' => $work['is_maintenance'],
                    'active' => $work['active'],
                ]);
            }

            $applicabilities = $this->applicabilities($user->tenantId());
            foreach ($preview['norms'] as $norm) {
                $workType = $workModels[$norm['workCode']];
                $hasRestrictions = $norm['specificity'] !== 'general';

                TimeNorm::query()->create([
                    'tenant_id' => $user->tenantId(),
                    'norm_id' => $norm['normId'],
                    'work_type_id' => $workType->id,
                    'applicability_id' => $hasRestrictions
                        ? $applicabilities['vehicle']->id
                        : $applicabilities['general']->id,
                    'vehicle_make_id' => $norm['vehicle_make_id'],
                    'vehicle_model_id' => $norm['vehicle_model_id'],
                    'vehicle_id' => $norm['vehicle_id'],
                    'vehicle_generation_id' => $norm['vehicle_generation_id'],
                    'vehicle_engine_variant_id' => $norm['vehicle_engine_variant_id'],
                    'vehicle_transmission_type_id' => $norm['vehicle_transmission_type_id'],
                    'vehicle_drive_type_id' => $norm['vehicle_drive_type_id'],
                    'modification_code' => $norm['modification_code'],
                    'vehicle_model' => '',
                    'hours' => $norm['hours'],
                    'source' => $norm['source'],
                    'active' => $norm['active'] && $workType->active,
                    'catalog_version' => $preview['catalogVersion'],
                    'catalog_import_id' => $batch->id,
                ]);
            }

            $summary = array_merge($preview['summary'], [
                'archivedWorks' => $archivedWorks,
                'archivedNorms' => $archivedNorms,
            ]);
            $batch->forceFill([
                'status' => 'applied',
                'summary' => $summary,
                'applied_at' => now(),
            ])->save();

            return array_merge($preview, [
                'importId' => $batch->uuid,
                'applied' => true,
                'summary' => $summary,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $validated @return array<int, array<string, mixed>> */
    private function warnings(array $validated): array
    {
        if ($validated['summary']['emptyNorms'] === 0) {
            return [];
        }

        return [[
            'code' => 'EMPTY_NORM_HOURS',
            'count' => $validated['summary']['emptyNorms'],
            'message' => 'Пустые нормы импортируются как NULL и требуют '
                . 'заполнения или ручного обоснования в заявке',
        ]];
    }

    /** @param array<string, mixed> $norm @return array<string, mixed> */
    private function presentNorm(array $norm): array
    {
        return array_merge($norm, [
            'normId' => $norm['norm_id'],
            'workCode' => $norm['work_code'],
            'specificity' => $this->specificity($norm),
            'displayCode' => $this->displayCode($norm),
        ]);
    }

    /** @param array<string, mixed> $norm */
    private function specificity(array $norm): string
    {
        if ($norm['vehicle_id'] !== null) {
            return 'vehicle';
        }
        if ($norm['vehicle_generation_id'] !== null) {
            return array_filter([
                $norm['vehicle_engine_variant_id'],
                $norm['vehicle_transmission_type_id'],
                $norm['vehicle_drive_type_id'],
                $norm['modification_code'],
            ], static fn (mixed $value): bool => $value !== null && $value !== '') === []
                ? 'generation'
                : 'generation_modification';
        }
        if ($norm['vehicle_make_id'] !== null && $norm['vehicle_model_id'] !== null) {
            return 'make_model';
        }
        if ($norm['vehicle_make_id'] !== null) {
            return 'make';
        }

        return 'general';
    }

    /** @param array<string, mixed> $norm */
    private function displayCode(array $norm): string
    {
        $parts = array_filter([
            $norm['vin'] !== '' ? 'VIN:' . $norm['vin'] : null,
            $norm['make'] ?: null,
            $norm['model'] ?: null,
            $norm['generation'] ?: null,
            $norm['engine'] ?: null,
            $norm['transmission'] ?: null,
            $norm['drive'] ?: null,
            $norm['modification_code'] !== null ? 'MOD:' . $norm['modification_code'] : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $parts === [] ? 'Общая' : implode(' / ', $parts);
    }

    /** @return array<string, TimeNormApplicability> */
    private function applicabilities(int $tenantId): array
    {
        $result = [];
        foreach ([
            'general' => 'Общая',
            'vehicle' => 'По авто',
        ] as $code => $name) {
            $result[$code] = TimeNormApplicability::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'normalized_name' => mb_strtolower($name)],
                [
                    'code' => $code,
                    'name' => $name,
                    'is_system' => true,
                    'active' => true,
                ],
            );
        }

        return $result;
    }

    private function assertManager(User $user): void
    {
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Импорт справочника работ доступен только УК',
            );
        }
    }
}
