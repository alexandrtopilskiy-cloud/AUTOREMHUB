<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\VehicleReferenceImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReplaceTestVehicleCatalog extends Command
{
    protected $signature = 'sto:replace-test-vehicle-catalog
        {file : XLSX нового каталога}
        {--apply : Очистить тестовые заявки и ТС и применить замену}';

    protected $description = 'Атомарно заменить каталог ТС и очистить тестовые документы';

    public function handle(VehicleReferenceImporter $importer): int
    {
        $file = (string) $this->argument('file');
        $preview = $importer->import($file, true, true, true);
        $this->info(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!$this->option('apply')) {
            $this->info('Проверка завершена. База не изменена. Для применения: --apply');
            return self::SUCCESS;
        }

        if (!$this->laravel->environment('testing') && !$this->laravel->isDownForMaintenance()) {
            $this->error('Перед очисткой остановите запись: php artisan down');
            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($importer, $file): array {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('replace-test-vehicle-catalog'))");
            DB::statement('LOCK TABLE repair_requests, vehicles, time_norms, vehicle_models, vehicle_makes IN ACCESS EXCLUSIVE MODE');
            // CASCADE goes only to dependent document tables, not to organizations or catalog works.
            DB::statement('TRUNCATE TABLE repair_requests RESTART IDENTITY CASCADE');
            DB::table('vehicle_change_requests')->delete();
            $archived = DB::table('time_norms')->whereNotNull('vehicle_id')->update([
                'vehicle_id' => null, 'active' => false, 'updated_at' => now(),
            ]);
            DB::table('vehicle_contacts')->delete();
            DB::table('vehicles')->delete();
            DB::table('catalog_records')->where('kind', 'vehicles')->delete();
            DB::table('document_locks')->where('resource_type', 'catalog_record')
                ->where('resource_key', 'like', 'vehicles:%')->delete();
            DB::table('document_locks')->where('resource_type', 'request')->delete();
            $import = $importer->import($file, false, true, true);
            // Keep brand/general/transmission rules when their references remain valid.
            $obsolete = ['vehicle_make_id' => 'vehicle_makes', 'vehicle_model_id' => 'vehicle_models',
                'vehicle_generation_id' => 'vehicle_generations', 'vehicle_engine_variant_id' => 'vehicle_engine_variants'];
            $archived += DB::table('time_norms')->where('active', true)
                ->where(function ($query) use ($obsolete): void {
                    foreach ($obsolete as $field => $table) {
                        $query->orWhereIn($field, DB::table($table)->where('active', false)->select('id'));
                    }
                })->update(['active' => false, 'updated_at' => now()]);
            return array_merge($import, ['archivedTechnicalNorms' => $archived]);
        });
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
