<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Смета, последней пропущенная УК (передача в СТО или согласование правок СТО).
 *
 * Прогон 900 ЗР (12.11.2026): после возврата УК правок СТО снимок сметы
 * перезаписывался текущей версией, и «Подтвердить» без исправлений уводил ЗР
 * Заказчику без согласования УК. Теперь при возврате СТО сравнивает смету
 * с согласованной УК.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->unsignedInteger('manager_approved_estimate_version')->nullable();
            $table->jsonb('manager_approved_estimate_snapshot')->nullable();
        });

        // Колонка теперь приводится к enum ReviewContext: неизвестные значения недопустимы.
        DB::table('repair_requests')
            ->whereNotIn('review_context', ['initial', 'station_changes', 'station_declined', 'customer_slot', 'manager_changes'])
            ->update(['review_context' => 'initial']);
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->dropColumn(['manager_approved_estimate_version', 'manager_approved_estimate_snapshot']);
        });
    }
};
