<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Список колонок таблиц, снятый при деплое после миграций
 * (`php artisan sto:schema-cache`, файл bootstrap/cache/schema-columns.php).
 *
 * Eloquent проверяет «защищённые» поля моделей с $guarded по реальному списку
 * колонок и для этого в каждом запросе PHP-FPM заново спрашивает у PostgreSQL
 * метаданные таблицы. С файлом этих запросов нет; без файла (локально, в тестах)
 * работает обычная логика Laravel.
 */
final class SchemaColumnCache
{
    /** @var array<string, list<string>>|null */
    private static ?array $columns = null;

    private static bool $loaded = false;

    public static function path(): string
    {
        return app()->bootstrapPath('cache/schema-columns.php');
    }

    /**
     * @return list<string>|null null — таблицы нет в кэше, нужен запрос к базе
     */
    public static function columns(string $table): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $path = self::path();
            $data = is_file($path) ? require $path : null;
            self::$columns = is_array($data) ? $data : null;
        }

        return self::$columns[$table] ?? null;
    }

    public static function forget(): void
    {
        self::$columns = null;
        self::$loaded = false;
    }
}
