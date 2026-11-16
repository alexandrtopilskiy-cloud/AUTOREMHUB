<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Индекс на каждый внешний ключ, у которого его ещё нет.
 *
 * PostgreSQL не создаёт индекс на ссылающейся стороне ключа сам. Без него
 * удаление или изменение строки в родительской таблице (ЗР, пользователь,
 * ТС, склад) проверяет дочернюю таблицу полным просмотром: удаление пачки ЗР
 * в меню «Разработчик» шло 47 с. Список берётся из каталога базы, поэтому
 * миграция находит все такие ключи, а не только перечисленные вручную
 * (часть добавлена в 2026_11_12_000048).
 *
 * Отдельно — покрывающий индекс для остатков склада (StockService::onHand:
 * SUM(quantity) по организации с группировкой по ЗЧ) — читается без обращения
 * к таблице.
 */
return new class extends Migration
{
    private const SUFFIX = '_fk_index';

    private const STOCK_INDEX = 'stock_movements_org_part_qty_index';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->unindexedForeignKeys() as $key) {
            $columns = implode(', ', array_map(fn (string $c) => '"' . $c . '"', $key['columns']));
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS "%s" ON "%s" (%s)',
                $this->indexName($key['table'], $key['columns']),
                $key['table'],
                $columns,
            ));
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS ' . self::STOCK_INDEX
            . ' ON stock_movements (organization_id, catalog_part_id) INCLUDE (quantity, warehouse_id)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . self::STOCK_INDEX);

        $names = DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname LIKE ?",
            ['%' . self::SUFFIX],
        );

        foreach ($names as $row) {
            DB::statement('DROP INDEX IF EXISTS "' . $row->indexname . '"');
        }
    }

    /**
     * Внешние ключи текущей схемы, у которых нет индекса, начинающегося
     * ровно с колонок ключа (в любом порядке).
     *
     * @return list<array{table: string, columns: list<string>}>
     */
    private function unindexedForeignKeys(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT rel.relname AS table_name,
                   (SELECT string_agg(a.attname, ',' ORDER BY k.n)
                      FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, n)
                      JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
                   ) AS columns
              FROM pg_constraint c
              JOIN pg_class rel ON rel.oid = c.conrelid
             WHERE c.contype = 'f'
               AND c.connamespace = current_schema()::regnamespace
               AND NOT EXISTS (
                   SELECT 1
                     FROM pg_index i
                    WHERE i.indrelid = c.conrelid
                      AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] @> c.conkey
                      AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] <@ c.conkey
               )
             ORDER BY 1, 2
            SQL);

        $keys = [];
        foreach ($rows as $row) {
            $columns = explode(',', (string) $row->columns);
            $keys[$row->table_name . ':' . $row->columns] = ['table' => $row->table_name, 'columns' => $columns];
        }

        return array_values($keys);
    }

    /** @param list<string> $columns */
    private function indexName(string $table, array $columns): string
    {
        $name = $table . '_' . implode('_', $columns) . self::SUFFIX;

        if (strlen($name) <= 63) {
            return $name;
        }

        return substr($table . '_' . implode('_', $columns), 0, 63 - strlen(self::SUFFIX) - 9)
            . '_' . substr(md5($name), 0, 8) . self::SUFFIX;
    }
};
