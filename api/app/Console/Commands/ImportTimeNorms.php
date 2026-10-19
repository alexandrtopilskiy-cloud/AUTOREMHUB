<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TimeNormImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

final class ImportTimeNorms extends Command
{
    protected $signature = 'sto:time-norms-import
        {tenant : Integer ID или UUID контура УК}
        {file : Путь к XLSX}
        {--dry-run : Только проверить файл и показать результат}';

    protected $description = 'Импортировать виды работ и нормы времени из XLSX';

    /**
     * @throws JsonException
     */
    public function handle(TimeNormImporter $importer): int
    {
        $tenant = $this->findTenant((string) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('Контур УК не найден');

            return self::FAILURE;
        }

        $result = $importer->import(
            $tenant->id,
            (string) $this->argument('file'),
            null,
            (bool) $this->option('dry-run'),
        );

        $this->info(json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    private function findTenant(string $key): ?Tenant
    {
        if (ctype_digit($key)) {
            return Tenant::query()->find((int) $key);
        }

        if (Str::isUuid($key)) {
            return Tenant::query()->where('uuid', $key)->first();
        }

        return null;
    }
}
