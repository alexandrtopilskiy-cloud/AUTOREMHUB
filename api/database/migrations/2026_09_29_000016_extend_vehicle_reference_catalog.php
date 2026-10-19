<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicle_makes', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('normalized_name');
            $table->index(['active', 'normalized_name']);
        });

        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->string('body_code', 150)->default('')->after('engine_index');
            $table->string('source_transmission', 150)->default('')->after('transmission');
            $table->string('source_drive_type', 150)->default('')->after('drive_type');
            $table->string('source_engine_type', 150)->default('')->after('engine_type');
            $table->boolean('active')->default(true)->after('fingerprint');
            $table->index(['active', 'normalized_name']);
            $table->index(['body_code']);
        });

        DB::statement(
            "COMMENT ON COLUMN vehicle_makes.active IS "
            . "'Признак присутствия марки в действующей редакции "
            . "внутреннего справочника'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_models.body_code IS "
            . "'Код кузова или поколение модели из внутреннего "
            . "справочника ТС'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_models.active IS "
            . "'Признак присутствия варианта модели в действующей "
            . "редакции справочника'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_models.source_transmission IS "
            . "'Исходное обозначение трансмиссии из импортированного "
            . "справочника'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_models.source_drive_type IS "
            . "'Исходное обозначение привода из импортированного справочника'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_models.source_engine_type IS "
            . "'Исходное обозначение типа двигателя из импортированного "
            . "справочника'"
        );
    }

    public function down(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->dropIndex(['body_code']);
            $table->dropIndex(['active', 'normalized_name']);
            $table->dropColumn([
                'body_code',
                'source_transmission',
                'source_drive_type',
                'source_engine_type',
                'active',
            ]);
        });

        Schema::table('vehicle_makes', function (Blueprint $table): void {
            $table->dropIndex(['active', 'normalized_name']);
            $table->dropColumn('active');
        });
    }
};
