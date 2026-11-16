<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SchemaColumnCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Снимает список колонок всех таблиц в bootstrap/cache/schema-columns.php.
 * Запускается при деплое сразу после migrate; --clear удаляет файл.
 */
final class CacheSchemaColumns extends Command
{
    protected $signature = 'sto:schema-cache {--clear : Удалить кэш колонок}';

    protected $description = 'Кэш списка колонок таблиц для проверки $guarded без запросов к схеме';

    public function handle(): int
    {
        $path = SchemaColumnCache::path();

        if ($this->option('clear')) {
            File::delete($path);
            SchemaColumnCache::forget();
            $this->info('Кэш колонок удалён');

            return self::SUCCESS;
        }

        $columns = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $columns[$table] ??= Schema::getColumnListing($table);
        }

        ksort($columns);
        File::put($path, '<?php return ' . var_export($columns, true) . ';' . PHP_EOL);
        SchemaColumnCache::forget();
        $this->info('Кэш колонок: ' . count($columns) . ' таблиц');

        return self::SUCCESS;
    }
}
