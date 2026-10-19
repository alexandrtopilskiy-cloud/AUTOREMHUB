<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VehicleReferenceService::ENGINE_TYPES и импорт XLSX допускают расширенную
 * редакцию типов двигателя (газ, газ/бензин, водородный), а CHECK из
 * миграции 000010 оставался на четырёх значениях — импорт такой строки
 * откатывал всю загрузку справочника ошибкой SQLSTATE 23514.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS vehicle_models_engine');
        DB::statement(
            "ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_engine "
            . "CHECK (engine_type IS NULL OR engine_type IN "
            . "('Бензин', 'дизель', 'гибрид', 'электро', 'газ', 'газ/бензин', 'водородный'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS vehicle_models_engine');
        DB::statement(
            "ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_engine "
            . "CHECK (engine_type IS NULL OR engine_type IN "
            . "('Бензин', 'дизель', 'гибрид', 'электро')) NOT VALID"
        );
    }
};
