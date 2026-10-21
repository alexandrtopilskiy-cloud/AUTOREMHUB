<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ВРЕМЕННО: сняты CHECK-ограничения на vehicle_models, мешающие импорту
 * нового каталога ТС (значения engine/transmission/drive не совпадают
 * с захардкоженными списками).
 *
 * TODO: вернуть ограничения (возможно, в виде справочников + FK)
 * после согласования бизнес-логики топлив/КПП/привода.
 *
 * Снятые определения (для восстановления):
 *   vehicle_models_engine       — CHECK (engine_type IS NULL OR engine_type::text = ANY (ARRAY['Бензин','дизель','гибрид','электро']))
 *   vehicle_models_transmission — CHECK (...)   <-- вставьте фактическое из pg_get_constraintdef
 *   vehicle_models_drive        — CHECK (...)   <-- вставьте фактическое
 *   vehicle_models_horsepower   — CHECK (...)   <-- вставьте фактическое
 *   vehicle_models_years        — CHECK (...)   <-- вставьте фактическое
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS vehicle_models_engine');
        DB::statement('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS vehicle_models_transmission');
        DB::statement('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS vehicle_models_drive');
        DB::statement('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS vehicle_models_horsepower');
        DB::statement('ALTER TABLE vehicle_models DROP CONSTRAINT IF EXISTS vehicle_models_years');
    }

    public function down(): void
    {
        // Восстановление — раскомментировать и подставить реальные определения,
        // когда бизнес-логика будет согласована.
        //
        // DB::statement("ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_engine
        //     CHECK (engine_type IS NULL OR engine_type::text = ANY (ARRAY['Бензин','дизель','гибрид','электро']))");
        // DB::statement("ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_transmission CHECK (...)");
        // DB::statement("ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_drive CHECK (...)");
        // DB::statement("ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_horsepower CHECK (...)");
        // DB::statement("ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_years CHECK (...)");
    }
};