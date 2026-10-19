<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\UserDirectoryImporter;
use Illuminate\Console\Command;

final class ImportUsers extends Command
{
    protected $signature = 'sto:users-import
        {tenant : Integer ID контура УК}
        {file : Путь к XLSX}
        {--dry-run : Проверить файл без записи в БД}';

    protected $description = 'Импортировать справочник пользователей из XLSX';

    public function handle(UserDirectoryImporter $importer): int
    {
        $tenant = Tenant::query()->find((int) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('Контур УК не найден.');

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');
        $result = $importer->import(
            $tenant,
            $file,
            (bool) $this->option('dry-run'),
        );

        $this->table(
            [
                'Всего',
                'Создано',
                'Обновлено',
                'Отозвано',
                'Случайные данные',
                'Документы',
            ],
            [[
                $result['total'],
                $result['created'],
                $result['updated'],
                $result['revoked'],
                $result['randomized'],
                $result['reassigned'],
            ]],
        );

        if ($this->option('dry-run')) {
            $this->info('Проверка завершена. Изменения в БД не внесены.');
        }

        return self::SUCCESS;
    }
}
