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
        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->string('review_context', 40)
                ->default('initial')
                ->after('estimate_version');
            $table->unsignedInteger('review_baseline_version')
                ->default(0)
                ->after('review_context');
            $table->jsonb('review_baseline_snapshot')
                ->nullable()
                ->after('review_baseline_version');
        });

        Schema::create('vehicle_acceptance_acts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('repair_request_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('attachment_id')
                ->nullable()
                ->unique()
                ->constrained('attachments')
                ->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->string('mode', 20);
            $table->jsonb('vehicle_snapshot');
            $table->jsonb('contact_snapshot')->nullable();
            $table->jsonb('driver_snapshot')->nullable();
            $table->unsignedBigInteger('mileage');
            $table->string('fuel_level', 20)->nullable();
            $table->text('condition')->default('');
            $table->jsonb('defect_types')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('diagram_marks')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('locked_at');
            $table->timestampsTz();
        });

        DB::statement(
            "ALTER TABLE vehicle_acceptance_acts ADD CONSTRAINT "
            . "vehicle_acceptance_acts_mode_check "
            . "CHECK (mode IN ('electronic', 'uploaded'))",
        );
        DB::statement(
            'ALTER TABLE vehicle_acceptance_acts ADD CONSTRAINT '
            . 'vehicle_acceptance_acts_fuel_level_check '
            . "CHECK (fuel_level IS NULL OR fuel_level IN ('empty', 'quarter', 'half', 'three_quarters', 'full'))",
        );

        DB::statement(
            "COMMENT ON TABLE vehicle_acceptance_acts IS "
            . "'App\\Models\\VehicleAcceptanceAct — неизменяемый акт приёмки ТС по заявке'",
        );
        DB::statement(
            "COMMENT ON COLUMN repair_requests.review_context IS "
            . "'Причина текущей маршрутизации: initial, station_changes, manager_changes, station_declined или customer_slot'",
        );
        DB::statement(
            "COMMENT ON COLUMN repair_requests.review_baseline_snapshot IS "
            . "'Снимок работ и запчастей на входе в текущий цикл согласования'",
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_acceptance_acts.mode IS "
            . "'electronic — заполнен в платформе; uploaded — загружен подписанный файл'",
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_acceptance_acts.diagram_marks IS "
            . "'Отметки внешних дефектов по зонам развёртки автомобиля'",
        );

        DB::statement(
            "UPDATE repair_requests SET review_context = 'station_changes', "
            . 'review_baseline_version = estimate_version WHERE stage = 5',
        );

        $this->remapStages(
            'CASE stage '
            . 'WHEN 5 THEN 1 '
            . 'WHEN 6 THEN 3 '
            . 'WHEN 7 THEN 4 '
            . 'WHEN 8 THEN 6 '
            . 'WHEN 9 THEN 7 '
            . 'WHEN 10 THEN 8 '
            . 'WHEN 11 THEN 9 '
            . 'WHEN 12 THEN 10 '
            . 'WHEN 13 THEN 11 '
            . 'ELSE stage END',
        );

        DB::statement('ALTER TABLE repair_requests DROP CONSTRAINT IF EXISTS request_stage');
        DB::statement(
            'ALTER TABLE repair_requests ADD CONSTRAINT request_stage '
            . 'CHECK (stage BETWEEN 0 AND 11)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE repair_requests DROP CONSTRAINT IF EXISTS request_stage');
        DB::statement(
            'ALTER TABLE repair_requests ADD CONSTRAINT request_stage '
            . 'CHECK (stage BETWEEN 0 AND 13)',
        );

        $this->remapStages(
            'CASE stage '
            . 'WHEN 3 THEN 6 '
            . 'WHEN 4 THEN 4 '
            . 'WHEN 5 THEN 7 '
            . 'WHEN 6 THEN 8 '
            . 'WHEN 7 THEN 9 '
            . 'WHEN 8 THEN 10 '
            . 'WHEN 9 THEN 11 '
            . 'WHEN 10 THEN 12 '
            . 'WHEN 11 THEN 13 '
            . 'ELSE stage END',
        );

        Schema::dropIfExists('vehicle_acceptance_acts');

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'review_context',
                'review_baseline_version',
                'review_baseline_snapshot',
            ]);
        });
    }

    private function remapStages(string $expression): void
    {
        foreach ([
            'repair_requests',
            'request_events',
            'request_status_periods',
            'request_approvals',
        ] as $table) {
            DB::statement("UPDATE {$table} SET stage = {$expression}");
        }
    }
};
