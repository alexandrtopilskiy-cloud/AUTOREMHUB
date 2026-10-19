<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\VehicleReferenceImporter;
use App\Services\XlsxTableReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class UpdatedVehicleCatalogTest extends TestCase
{
    public function test_updated_source_has_only_exact_duplicates_and_consistent_generation_periods(): void
    {
        $reader = new XlsxTableReader();
        $importer = new VehicleReferenceImporter($reader);
        $path = dirname(__DIR__, 3) . '/database/data/vehicle_reference_20261005.xlsx';
        $rows = (new ReflectionMethod($importer, 'normalizeRows'))->invoke(
            $importer,
            $reader->rows($path, [['марка'], ['модель']]),
            true,
        );

        $this->assertCount(11045, $rows);
        $this->assertSame(73, (new ReflectionProperty($importer, 'skippedDuplicates'))->getValue($importer));
        $this->assertCount(10, array_unique(array_column($rows, 'makeKey')));
        $periods = [];
        foreach ($rows as $row) {
            $key = json_encode([$row['make'], $row['model'], $row['bodyCode']], JSON_THROW_ON_ERROR);
            $period = [$row['modelYearFrom'], $row['modelYearTo']];
            if (isset($periods[$key])) {
                $this->assertSame($periods[$key], $period);
            }
            $periods[$key] = $period;
        }
    }
}
