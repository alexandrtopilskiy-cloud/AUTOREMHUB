<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Блокировка документа Рекомендаций ТС при переносе позиций в ЗР.
 * DocumentLockService использует тип 'recommendation', но ограничение
 * таблицы его не допускало, и перенос из интерфейса падал с ошибкой 500.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE document_locks DROP CONSTRAINT IF EXISTS document_locks_resource_type');
        DB::statement(
            "ALTER TABLE document_locks ADD CONSTRAINT document_locks_resource_type "
            . "CHECK (resource_type IN ('request', 'catalog_record', 'catalog_list', 'recommendation'))"
        );
    }

    public function down(): void
    {
        DB::statement("DELETE FROM document_locks WHERE resource_type = 'recommendation'");
        DB::statement('ALTER TABLE document_locks DROP CONSTRAINT IF EXISTS document_locks_resource_type');
        DB::statement(
            "ALTER TABLE document_locks ADD CONSTRAINT document_locks_resource_type "
            . "CHECK (resource_type IN ('request', 'catalog_record', 'catalog_list'))"
        );
    }
};
