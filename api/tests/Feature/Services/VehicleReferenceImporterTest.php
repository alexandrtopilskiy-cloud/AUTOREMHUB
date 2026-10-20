<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\VehicleReferenceImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CreatesXlsxFiles;
use Tests\TestCase;

final class VehicleReferenceImporterTest extends TestCase
{
    use CreatesXlsxFiles;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        $this->deleteXlsxFiles();

        parent::tearDown();
    }

    public function test_exact_duplicates_can_be_skipped_explicitly(): void
    {
        $row = $this->row('Chery', 'Tiggo', '2020', '2026', 'SQRE4T15');
        $path = $this->xlsx([$this->headers(), $row, $row]);

        $result = $this->importer()->import($path, true, true, true);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['skippedDuplicates']);
        $this->assertSame(0, VehicleModel::query()->count());
    }

    public function test_dry_run_reports_changes_without_touching_database(): void
    {
        $path = $this->xlsx([
            $this->headers(),
            $this->row('Jetour', 'Dashing', '2022', '2026', 'SQRE4T15'),
            $this->row('Lada', 'Vesta', '2015', '2025', 'VAZ-21179'),
        ]);

        $result = $this->importer()->import($path, true);

        $this->assertSame(2, $result['makes']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['existing']);
        $this->assertSame(0, $result['deactivated']);
        $this->assertSame(2, $result['total']);
        $this->assertTrue($result['dryRun']);
        $this->assertSame(0, VehicleMake::query()->count());
        $this->assertSame(0, VehicleModel::query()->count());
    }

    public function test_import_persists_normalized_model_and_source_values(): void
    {
        $path = $this->xlsx([
            $this->headers(),
            $this->row(
                'Jetour',
                'Dashing',
                '2022',
                '2026',
                'SQRE4T15',
                'B9',
                '147,0',
                '7DCT',
                '4-Wheel Drive',
                'Бензин/газ',
            ),
        ]);

        $result = $this->importer()->import($path);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['existing']);
        $this->assertFalse($result['dryRun']);

        $model = VehicleModel::query()->with('make')->firstOrFail();
        $this->assertSame('Jetour', $model->make->name);
        $this->assertSame('Dashing', $model->name);
        $this->assertSame(2022, $model->model_year_from);
        $this->assertSame(2026, $model->model_year_to);
        $this->assertSame('147.0', $model->horsepower);
        $this->assertSame('РКПП', $model->transmission);
        $this->assertSame('7DCT', $model->source_transmission);
        $this->assertSame('полный', $model->drive_type);
        $this->assertSame('4-Wheel Drive', $model->source_drive_type);
        $this->assertSame('Бензин', $model->engine_type);
        $this->assertSame('Бензин/газ', $model->source_engine_type);
    }

    public function test_second_import_marks_model_as_existing(): void
    {
        $path = $this->xlsx([
            $this->headers(),
            $this->row('Jetour', 'Dashing', '2022', '2026', 'SQRE4T15'),
        ]);

        $this->importer()->import($path);
        $second = $this->importer()->import($path);

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['existing']);
        $this->assertSame(1, VehicleMake::query()->count());
        $this->assertSame(1, VehicleModel::query()->count());
    }

    public function test_replace_deactivates_models_missing_from_new_file(): void
    {
        $initial = $this->xlsx([
            $this->headers(),
            $this->row('Jetour', 'Dashing', '2022', '2026', 'SQRE4T15'),
            $this->row('Jetour', 'X70 Plus', '2021', '2026', 'SQRE4T15C'),
        ]);
        $replacement = $this->xlsx([
            $this->headers(),
            $this->row('Jetour', 'Dashing', '2022', '2026', 'SQRE4T15'),
        ]);

        $this->importer()->import($initial);
        $result = $this->importer()->import($replacement, false, true);

        $this->assertSame(1, $result['deactivated']);
        $this->assertTrue(
            VehicleModel::query()
                ->where('name', 'Dashing')
                ->firstOrFail()
                ->active,
        );
        $this->assertFalse(
            VehicleModel::query()
                ->where('name', 'X70 Plus')
                ->firstOrFail()
                ->active,
        );
    }

    public function test_import_rejects_duplicate_model_variant(): void
    {
        $row = $this->row(
            'Jetour',
            'Dashing',
            '2022',
            '2026',
            'SQRE4T15',
        );
        $path = $this->xlsx([$this->headers(), $row, $row]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('запись модели повторяется');

        $this->importer()->import($path);
    }

    public function test_import_rejects_invalid_model_year_range(): void
    {
        $path = $this->xlsx([
            $this->headers(),
            $this->row('Jetour', 'Dashing', '2026', '2022', 'SQRE4T15'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('неверный диапазон модельных лет');

        $this->importer()->import($path);
    }

    public function test_import_rejects_invalid_horsepower(): void
    {
        $path = $this->xlsx([
            $this->headers(),
            $this->row(
                'Jetour',
                'Dashing',
                '2022',
                '2026',
                'SQRE4T15',
                'B9',
                '5000',
            ),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('мощность должна быть от 0,1 до 3000,0');

        $this->importer()->import($path);
    }

    private function importer(): VehicleReferenceImporter
    {
        return app(VehicleReferenceImporter::class);
    }

    /**
     * @return array<int, string>
     */
    private function headers(): array
    {
        return [
            'Марка',
            'Модель',
            'Модельный год от',
            'Модельный год до',
            'Индекс двигателя',
            'Номер кузова (кузов)',
            'Мощность, л.с.',
            'Вид трансмиссии',
            'Тип привода',
            'Тип двигателя',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function row(
        string $make,
        string $model,
        string $yearFrom,
        string $yearTo,
        string $engineIndex,
        string $bodyCode = '',
        string $horsepower = '',
        string $transmission = '',
        string $driveType = '',
        string $engineType = '',
    ): array {
        return [
            $make,
            $model,
            $yearFrom,
            $yearTo,
            $engineIndex,
            $bodyCode,
            $horsepower,
            $transmission,
            $driveType,
            $engineType,
        ];
    }

    /**
     * @param array<int, array<int, scalar|null>> $rows
     */
    private function xlsx(array $rows): string
    {
        return $this->createXlsxFile($rows, 'vehicle-reference-import-');
    }
}
