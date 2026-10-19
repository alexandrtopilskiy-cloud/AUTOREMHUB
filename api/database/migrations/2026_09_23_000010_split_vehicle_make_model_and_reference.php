<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicle_makes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->timestampsTz();
        });

        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vehicle_make_id')
                ->constrained('vehicle_makes')
                ->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->unsignedSmallInteger('model_year_from')->nullable();
            $table->unsignedSmallInteger('model_year_to')->nullable();
            $table->string('engine_index', 100)->default('');
            $table->decimal('horsepower', 6, 1)->nullable();
            $table->string('transmission', 20)->nullable();
            $table->string('drive_type', 20)->nullable();
            $table->string('engine_type', 20)->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->timestampsTz();

            $table->index(['vehicle_make_id', 'normalized_name']);
        });

        DB::statement(
            "ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_years "
            . "CHECK ((model_year_from IS NULL OR model_year_from BETWEEN 1900 AND 2100) "
            . "AND (model_year_to IS NULL OR model_year_to BETWEEN 1900 AND 2100) "
            . "AND (model_year_from IS NULL OR model_year_to IS NULL "
            . "OR model_year_to >= model_year_from))"
        );
        DB::statement(
            "ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_horsepower "
            . "CHECK (horsepower IS NULL OR horsepower BETWEEN 0.1 AND 3000.0)"
        );
        DB::statement(
            "ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_transmission "
            . "CHECK (transmission IS NULL OR transmission IN "
            . "('МКПП', 'РКПП', 'АКПП', 'CVT', 'электро'))"
        );
        DB::statement(
            "ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_drive "
            . "CHECK (drive_type IS NULL OR drive_type IN "
            . "('передний', 'задний', 'полный'))"
        );
        DB::statement(
            "ALTER TABLE vehicle_models ADD CONSTRAINT vehicle_models_engine "
            . "CHECK (engine_type IS NULL OR engine_type IN "
            . "('Бензин', 'дизель', 'гибрид', 'электро'))"
        );

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('make')->default('')->after('name');
            $table->string('model')->default('')->after('make');
            $table->foreignId('vehicle_model_id')
                ->nullable()
                ->after('model')
                ->constrained('vehicle_models')
                ->restrictOnDelete();
            $table->index(['make', 'model']);
        });

        DB::statement(
            "UPDATE vehicles SET make = CASE "
            . "WHEN position(' ' IN btrim(name)) > 0 "
            . "THEN split_part(btrim(name), ' ', 1) ELSE btrim(name) END, "
            . "model = CASE WHEN position(' ' IN btrim(name)) > 0 "
            . "THEN btrim(substr(btrim(name), position(' ' IN btrim(name)) + 1)) "
            . "ELSE '' END"
        );

        DB::statement(
            "COMMENT ON TABLE vehicle_makes IS "
            . "'App\\Models\\VehicleMake — внутренний справочник марок автомобилей'"
        );
        DB::statement(
            "COMMENT ON TABLE vehicle_models IS "
            . "'App\\Models\\VehicleModel — модели и технические варианты автомобилей'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_models.fingerprint IS "
            . "'SHA-256 нормализованных признаков варианта; обеспечивает идемпотентный импорт'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicles.make IS "
            . "'Марка автомобиля, сохранённая отдельно от модели'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicles.model IS "
            . "'Модель автомобиля, сохранённая отдельно от марки'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicles.vehicle_model_id IS "
            . "'Выбранная запись внутреннего справочника моделей автомобилей'"
        );
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropIndex(['make', 'model']);
            $table->dropConstrainedForeignId('vehicle_model_id');
            $table->dropColumn(['make', 'model']);
        });

        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_makes');
    }
};
