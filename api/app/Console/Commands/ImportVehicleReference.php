<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\VehicleReferenceImporter;
use Illuminate\Console\Command;
use JsonException;

final class ImportVehicleReference extends Command
{
    protected $signature = 'sto:vehicle-reference-import
        {file? : Путь к XLSX; по умолчанию используется файл из database/data}
        {--dry-run : Только проверить файл и показать результат}
        {--append : Добавить записи без деактивации отсутствующих моделей}';

    protected $description = 'Импортировать марки и модели автомобилей из XLSX';

    /**
     * @throws JsonException
     */
    public function handle(VehicleReferenceImporter $importer): int
    {
        $file = (string) ($this->argument('file')
            ?: database_path('data/vehicle_reference_20261005.xlsx'));
        $result = $importer->import(
            $file,
            (bool) $this->option('dry-run'),
            !(bool) $this->option('append'),
            true,
        );

        $this->info(json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
