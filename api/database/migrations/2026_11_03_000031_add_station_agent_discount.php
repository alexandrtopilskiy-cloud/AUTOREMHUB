<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Агентская скидка СТО: задаётся в реквизитах СТО (по умолчанию 10 %)
 * и фиксируется в каждой строке работ и запчастей заявки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->decimal('agent_discount_percent', 5, 2)
                ->default(10)
                ->comment('Агентская скидка СТО в пользу УК, %');
        });

        foreach (['request_works', 'request_parts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('agent_discount_percent', 5, 2)
                    ->nullable()
                    ->comment('Агентская скидка СТО на строку, % (из реквизитов СТО)');
            });

            DB::statement(
                "UPDATE {$tableName} AS line SET agent_discount_percent = s.agent_discount_percent "
                . 'FROM repair_requests r JOIN stations s ON s.id = r.assigned_station_id '
                . 'WHERE r.id = line.repair_request_id',
            );
        }

        DB::statement(
            'ALTER TABLE stations ADD CONSTRAINT stations_agent_discount_range '
            . 'CHECK (agent_discount_percent BETWEEN 0 AND 100)',
        );
    }

    public function down(): void
    {
        foreach (['request_works', 'request_parts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('agent_discount_percent');
            });
        }

        DB::statement('ALTER TABLE stations DROP CONSTRAINT IF EXISTS stations_agent_discount_range');

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn('agent_discount_percent');
        });
    }
};
