<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Снимаем CHECK-constraint edo_documents_meta_check.
     *
     * Причина: constraint требует, чтобы meta всегда был JSON-объектом,
     * но в коде удобно передавать пустой массив `[]`, который PostgreSQL
     * видит как JSON-массив. Постоянно оборачивать в (object) — шум.
     *
     * Ответственность за форму meta переносится на PHP-слой
     * (cast 'array' + код, который пишет/читает meta).
     *
     * TODO (refactoring): вернуть constraint или ввести строгий
     * ValueObject для meta, если понадобится целостность на уровне БД.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE edo_documents DROP CONSTRAINT IF EXISTS edo_documents_meta_check');
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE edo_documents ADD CONSTRAINT edo_documents_meta_check "
            . "CHECK (jsonb_typeof(meta) = 'object')"
        );
    }
};
