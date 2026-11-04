<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Комментарии к строкам работ и запчастей заявки (хранятся только в заявке,
 * справочники не затрагиваются) и согласование изменений на стадии «В работе».
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['request_works', 'request_parts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->text('comment')->nullable()
                    ->comment('Комментарий к строке, только для этой заявки');
                $table->text('change_note')->nullable()
                    ->comment('Примечание к изменению строки на стадии «В работе»');
            });
        }

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->jsonb('work_change')->nullable()
                ->comment('Изменение работ/ЗЧ на стадии «В работе»: статус, исходная смета, решения');
        });
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->dropColumn('work_change');
        });

        foreach (['request_works', 'request_parts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['comment', 'change_note']);
            });
        }
    }
};
