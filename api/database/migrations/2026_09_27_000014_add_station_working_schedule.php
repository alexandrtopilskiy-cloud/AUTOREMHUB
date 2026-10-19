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
        Schema::table('stations', function (Blueprint $table): void {
            $table->time('workday_start')
                ->default('10:00')
                ->after('active');
            $table->time('workday_end')
                ->default('20:00')
                ->after('workday_start');
            $table->jsonb('working_days')
                ->default(DB::raw("'[1,2,3,4,5,6,7]'::jsonb"))
                ->after('workday_end');
        });

        DB::statement(
            'ALTER TABLE stations ADD CONSTRAINT stations_working_hours '
            . 'CHECK (workday_start < workday_end)'
        );
        DB::statement(
            'ALTER TABLE stations ADD CONSTRAINT stations_working_days '
            . "CHECK (jsonb_typeof(working_days) = 'array' "
            . 'AND jsonb_array_length(working_days) BETWEEN 1 AND 7 '
            . "AND working_days <@ '[1,2,3,4,5,6,7]'::jsonb)"
        );

        DB::statement(
            "COMMENT ON COLUMN stations.workday_start IS "
            . "'Локальное время начала рабочего дня СТО в формате ЧЧ:ММ'"
        );
        DB::statement(
            "COMMENT ON COLUMN stations.workday_end IS "
            . "'Локальное время окончания рабочего дня СТО в формате ЧЧ:ММ'"
        );
        DB::statement(
            "COMMENT ON COLUMN stations.working_days IS "
            . "'Рабочие дни СТО по ISO-8601: 1 — понедельник, 7 — воскресенье'"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE stations DROP CONSTRAINT IF EXISTS stations_working_days'
        );
        DB::statement(
            'ALTER TABLE stations DROP CONSTRAINT IF EXISTS stations_working_hours'
        );

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn([
                'workday_start',
                'workday_end',
                'working_days',
            ]);
        });
    }
};
