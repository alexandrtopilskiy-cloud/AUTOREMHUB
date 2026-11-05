<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление строк из Рекомендаций ТС (решение от 05.11.2026). Строка
 * скрывается из документа, но остаётся в истории (кто и когда удалил).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('recommendation_items', function (Blueprint $table): void {
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recommendation_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('deleted_at');
        });
    }
};
