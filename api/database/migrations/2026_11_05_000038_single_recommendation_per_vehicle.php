<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Рекомендации — отдельный документ по ТС (решение от 05.11.2026).
 *
 * Раньше каждый перенос позиций из ЗР создавал новый документ REC. Теперь у
 * ТС один документ, а каждая строка помнит, из какой ЗР она пришла.
 * Существующие документы одного ТС сливаются в самый ранний (его номер
 * сохраняется), строки переходят в него, остальные документы удаляются.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('recommendation_items', function (Blueprint $table): void {
            $table->foreignId('source_request_id')
                ->nullable()
                ->after('recommendation_id')
                ->constrained('repair_requests')
                ->nullOnDelete();
        });

        // Строка наследует ЗР-источник своего прежнего документа.
        DB::statement(<<<'SQL'
            UPDATE recommendation_items AS item
            SET source_request_id = doc.source_request_id
            FROM recommendations AS doc
            WHERE doc.id = item.recommendation_id
        SQL);

        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE recommendation_merge AS
            SELECT id,
                   uuid,
                   min(id) OVER (PARTITION BY tenant_id, vehicle_id) AS keep_id
            FROM recommendations
        SQL);

        DB::statement(<<<'SQL'
            UPDATE recommendation_items AS item
            SET recommendation_id = merge.keep_id
            FROM recommendation_merge AS merge
            WHERE merge.id = item.recommendation_id
              AND merge.id <> merge.keep_id
        SQL);

        // Объединённый документ получает данные последнего переноса и новую
        // ревизию, чтобы открытые вкладки перечитали его.
        DB::statement(<<<'SQL'
            UPDATE recommendations AS keep
            SET revision = agg.revision + 1,
                source_request_id = agg.source_request_id,
                customer_organization_id = agg.customer_organization_id,
                vin = agg.vin,
                updated_at = agg.updated_at
            FROM (
                SELECT DISTINCT ON (merge.keep_id)
                       merge.keep_id,
                       max(doc.revision) OVER (PARTITION BY merge.keep_id) AS revision,
                       doc.source_request_id,
                       doc.customer_organization_id,
                       doc.vin,
                       max(doc.updated_at) OVER (PARTITION BY merge.keep_id) AS updated_at
                FROM recommendation_merge AS merge
                JOIN recommendations AS doc ON doc.id = merge.id
                WHERE merge.keep_id IN (
                    SELECT keep_id FROM recommendation_merge GROUP BY keep_id HAVING count(*) > 1
                )
                ORDER BY merge.keep_id, doc.id DESC
            ) AS agg
            WHERE keep.id = agg.keep_id
        SQL);

        DB::statement(<<<'SQL'
            DELETE FROM document_locks
            WHERE resource_type = 'recommendation'
              AND resource_key IN (
                  SELECT uuid::text FROM recommendation_merge WHERE id <> keep_id
              )
        SQL);

        DB::statement(<<<'SQL'
            DELETE FROM recommendations
            WHERE id IN (SELECT id FROM recommendation_merge WHERE id <> keep_id)
        SQL);

        DB::statement('DROP TABLE recommendation_merge');

        Schema::table('recommendations', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'vehicle_id']);
        });

        DB::statement("COMMENT ON TABLE recommendations IS 'App\\Models\\Recommendation — документ рекомендаций, один на ТС'");
    }

    public function down(): void
    {
        // Слияние документов необратимо: откатываются только схема и индекс.
        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'vehicle_id']);
        });
        Schema::table('recommendation_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_request_id');
        });
    }
};
