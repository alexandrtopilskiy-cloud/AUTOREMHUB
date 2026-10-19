<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\VehicleImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

final class ImportVehicles extends Command
{
    protected $signature = 'sto:vehicles-import
        {organization : Integer ID или UUID организации}
        {file : Путь к XLSX/CSV}
        {--dry-run : Только показать, что будет сделано}';

    protected $description = 'Импортировать XLSX/CSV; VIN уникален, повторный импорт обновляет автомобили своей организации';

    /**
     * @throws JsonException
     */
    public function handle(VehicleImporter $importer): int
    {
        $key = (string) $this->argument('organization');
        $org = $this->findOrganization($key);

        if (!$org) {
            $this->error('Организация не найдена');

            return self::FAILURE;
        }

        $result = $importer->import(
            $org,
            (string) $this->argument('file'),
            (bool) $this->option('dry-run'),
        );

        $this->info(
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return self::SUCCESS;
    }

    /**
     * Ищет организацию по числовому ID или UUID.
     */
    private function findOrganization(string $key): ?Organization
    {
        if ($key === '') {
            return null;
        }

        if (ctype_digit($key)) {
            return Organization::query()->find((int) $key);
        }

        if (Str::isUuid($key)) {
            return Organization::query()->where('uuid', $key)->first();
        }

        return null;
    }
}