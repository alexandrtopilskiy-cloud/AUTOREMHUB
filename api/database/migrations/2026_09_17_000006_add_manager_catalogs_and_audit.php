<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->decimal('labor_rate_coefficient', 8, 3)
                ->default(1)
                ->after('baseline_service_at');
        });

        DB::statement(
            'ALTER TABLE vehicles ADD CONSTRAINT vehicles_labor_rate_coefficient_positive '
            . 'CHECK (labor_rate_coefficient > 0 AND labor_rate_coefficient <= 100)'
        );

        Schema::create('catalog_parts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('article')->default('');
            $table->string('manufacturer')->default('');
            $table->string('unit', 30)->default('шт.');
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'article']);
        });

        Schema::create('catalog_change_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('catalog_kind', 80);
            $table->uuid('record_uuid')->nullable();
            $table->string('record_label')->default('');
            $table->string('action', 40);
            $table->jsonb('before_payload')->nullable();
            $table->jsonb('after_payload')->nullable();
            $table->jsonb('changed_fields');
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();

            $table->index(['tenant_id', 'catalog_kind', 'record_uuid'], 'catalog_history_lookup');
        });

        DB::statement(
            "COMMENT ON TABLE catalog_parts IS "
            . "'App\\Models\\CatalogPart — редактируемый УК справочник запчастей'"
        );
        DB::statement(
            "COMMENT ON TABLE catalog_change_events IS "
            . "'App\\Models\\CatalogChangeEvent — неизменяемый журнал "
            . "изменений справочников'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicles.labor_rate_coefficient IS "
            . "'Поправочный коэффициент нормо-часа; редактируется только "
            . "Управляющей компанией'"
        );
        DB::statement(
            "COMMENT ON COLUMN catalog_change_events.record_uuid IS "
            . "'UUID изменённой записи справочника без полиморфного "
            . "внешнего ключа'"
        );
        DB::statement(
            "COMMENT ON COLUMN catalog_change_events.changed_fields IS "
            . "'Список имён изменённых атрибутов'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_change_events');
        Schema::dropIfExists('catalog_parts');

        DB::statement(
            'ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_labor_rate_coefficient_positive'
        );

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn('labor_rate_coefficient');
        });
    }
};
