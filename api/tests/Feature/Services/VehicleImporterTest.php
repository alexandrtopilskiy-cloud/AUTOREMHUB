<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\VehicleImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

final class VehicleImporterTest extends TestCase
{
    use DatabaseTransactions;

    private VehicleImporter $importer;
    private Tenant $tenant;
    private Organization $customer;
    private Organization $station;

    /** @var array<int, string> временные файлы для удаления после теста */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');

        $this->importer = app(VehicleImporter::class);

        $this->tenant   = Tenant::query()->create(['name' => 'Т']);
        $this->customer = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'Заказчик',
        ]);
        $this->station = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'station',
            'name'      => 'СТО',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /* ─────────────────── CSV: чтение ─────────────────── */

    public function test_read_csv_with_comma_delimiter(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        CSV);

        $rows = $this->importer->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('LVUDB21B2RA042817', $rows[0]['vin']);
        $this->assertSame('Toyota', $rows[0]['make']);
        $this->assertSame('Camry', $rows[0]['model']);
        $this->assertSame('А123АА178', $rows[0]['plate']);
        $this->assertSame(2020, $rows[0]['year']);
    }

    public function test_read_csv_with_semicolon_delimiter(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль;VIN;Гос. номер;Год выпуска
        Kia Rio;LVUDB21B2RA042817;А123АА178;2021
        CSV);

        $rows = $this->importer->read($path);

        $this->assertSame('Kia', $rows[0]['make']);
        $this->assertSame(2021, $rows[0]['year']);
    }

    public function test_read_csv_strips_utf8_bom_from_header(): void
    {
        $path = $this->csv("\xEF\xBB\xBFАвтомобиль,VIN,Гос. номер,Год выпуска\n"
        . "Lada Vesta,LVUDB21B2RA042817,А123АА178,2019\n");

        $rows = $this->importer->read($path);

        $this->assertSame('Lada', $rows[0]['make']);
    }

    public function test_read_csv_skips_empty_rows(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        ,,,
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        ,,,
        CSV);

        $rows = $this->importer->read($path);

        $this->assertCount(1, $rows);
    }

    public function test_read_csv_with_mileage_column(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска,Пробег на прошлом ТО
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020,15000
        CSV);

        $rows = $this->importer->read($path);

        $this->assertSame(15000, $rows[0]['baseline_service_mileage']);
    }

    /* ─────────────────── CSV: ошибки чтения ─────────────────── */

    public function test_read_fails_on_missing_file(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Файл не найден');

        $this->importer->read('/tmp/does-not-exist-' . uniqid() . '.csv');
    }

    public function test_read_fails_on_unsupported_extension(): void
    {
        $path = $this->tmpFile('data.txt', 'irrelevant');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Поддерживаются XLSX и CSV');

        $this->importer->read($path);
    }

    public function test_read_fails_on_empty_file(): void
    {
        $path = $this->csv('');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('В файле не найдено автомобилей');

        $this->importer->read($path);
    }

    public function test_read_fails_when_vin_column_missing(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,Гос. номер,Год выпуска
        Toyota Camry,А123АА178,2020
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Не найден столбец VIN');   // ← было 'В файле не найдено автомобилей'

        $this->importer->read($path);
    }

    public function test_read_fails_when_required_column_missing(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,2020
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Ожидаются столбцы');

        $this->importer->read($path);
    }

    /* ─────────────────── CSV: валидация строк ─────────────────── */

    public function test_read_fails_on_invalid_vin(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,SHORTVIN,А123АА178,2020
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('VIN должен содержать 17');

        $this->importer->read($path);
    }

    public function test_read_fails_on_duplicate_vin_in_file(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        Kia Rio,LVUDB21B2RA042817,В456ВВ178,2021
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Повтор VIN в файле');

        $this->importer->read($path);
    }

    public function test_read_fails_on_year_out_of_range(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,1800
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Проверьте модель и год');

        $this->importer->read($path);
    }

    public function test_read_fails_on_invalid_mileage(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска,Пробег на прошлом ТО
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020,abc
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Проверьте пробег');

        $this->importer->read($path);
    }

    /* ─────────────────── import(): создание и обновление ─────────────────── */

    public function test_import_creates_vehicles(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        Kia Rio,LVUDB21B2RA042818,В456ВВ178,2021
        CSV);

        $result = $this->importer->import($this->customer, $path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['total']);
        $this->assertFalse($result['dryRun']);
        $this->assertSame(2, Vehicle::query()->count());
    }

    public function test_import_dry_run_does_not_persist(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        CSV);

        $result = $this->importer->import($this->customer, $path, true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertTrue($result['dryRun']);
        $this->assertSame(0, Vehicle::query()->count());
    }

    public function test_import_is_idempotent(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        CSV);

        $this->importer->import($this->customer, $path);
        $again = $this->importer->import($this->customer, $path);

        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['updated']);
        $this->assertSame(1, Vehicle::query()->count());
    }

    public function test_import_fails_for_non_customer_organization(): void
    {
        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('импортируются в организацию Заказчика');

        $this->importer->import($this->station, $path);
    }

    public function test_import_fails_when_vin_belongs_to_other_organization(): void
    {
        Vehicle::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $this->station->id,
            'name'            => 'Занятый',
            'vin'             => 'LVUDB21B2RA042817',
            'plate'           => 'А123АА178',
            'plate_normalized' => 'А123АА178',
            'year'            => 2020,
            'labor_hour_rate' => 100,
        ]);

        $path = $this->csv(<<<CSV
        Автомобиль,VIN,Гос. номер,Год выпуска
        Toyota Camry,LVUDB21B2RA042817,А123АА178,2020
        CSV);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('уже принадлежит другой организации');

        $this->importer->import($this->customer, $path);
    }

    /* ─────────────────── XLSX: чтение ─────────────────── */

    public function test_read_xlsx_with_vin_column(): void
    {
        $path = $this->makeXlsx([
            ['Автомобиль', 'VIN', 'Гос. номер', 'Год выпуска'],
            ['Toyota Camry', 'LVUDB21B2RA042817', 'А123АА178', '2020'],
        ]);

        $rows = $this->importer->read($path);

        $this->assertSame('LVUDB21B2RA042817', $rows[0]['vin']);
    }

    public function test_read_xlsx_fails_without_vin_sheet(): void
    {
        $path = $this->makeXlsx([
            ['Автомобиль', 'Гос. номер'],
            ['Toyota', 'А123АА178'],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('лист с колонкой VIN');

        $this->importer->read($path);
    }

    public function test_read_fails_for_broken_xlsx_archive(): void
    {
        $path = $this->tmpFile('broken.xlsx', 'not-a-zip');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Не удалось открыть XLSX');

        $this->importer->read($path);
    }

    /* ─────────────────── helpers ─────────────────── */

    private function csv(string $content): string
    {
        return $this->tmpFile(uniqid('vehicle-import-', true) . '.csv', $content);
    }

    private function tmpFile(string $name, string $content): string
    {
        $path = sys_get_temp_dir() . '/' . $name;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
 * @param array<int, array<int, string>> $rows
 */
    private function makeXlsx(array $rows, bool $useSharedStrings = false): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('vehicle-import-', true) . '.xlsx';
        $this->tmpFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // [Content_Types].xml — добавим sharedStrings, если используется
        $sharedContentType = $useSharedStrings
            ? '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            : '';

        $zip->addFromString('[Content_Types].xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  {$sharedContentType}
</Types>
XML);

        // xl/workbook.xml — как было
        $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheets><sheet name="Sheet1" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets>
</workbook>
XML);

        if ($useSharedStrings) {
            // Собрать все строки в плоский список
            $flat = [];
            foreach ($rows as $row) {
                foreach ($row as $value) {
                    $flat[] = (string) $value;
                }
            }

            $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'count="' . count($flat) . '" uniqueCount="' . count($flat) . '">';

            foreach ($flat as $s) {
                $sharedXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
            }

            $sharedXml .= '</sst>';
            $zip->addFromString('xl/sharedStrings.xml', $sharedXml);

            // Строки листа — с t="s", значение = индекс
            $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

            $index = 0;

            foreach ($rows as $rowIndex => $row) {
                $sheet .= '<row r="' . ($rowIndex + 1) . '">';

                foreach ($row as $colIndex => $value) {
                    $colLetter = chr(ord('A') + $colIndex);
                    $sheet .= '<c r="' . $colLetter . ($rowIndex + 1) . '" t="s">'
                    . '<v>' . $index++ . '</v></c>';
                }

                $sheet .= '</row>';
            }

            $sheet .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        } else {
            // inlineStr — как было
            $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

            foreach ($rows as $rowIndex => $row) {
                $sheet .= '<row r="' . ($rowIndex + 1) . '">';

                foreach ($row as $colIndex => $value) {
                    $colLetter = chr(ord('A') + $colIndex);
                    $sheet .= '<c r="' . $colLetter . ($rowIndex + 1) . '" t="inlineStr">'
                    . '<is><t>' . htmlspecialchars((string) $value, ENT_XML1) . '</t></is></c>';
                }

                $sheet .= '</row>';
            }

            $sheet .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        }

        $zip->close();

        return $path;
    }

    public function test_read_xlsx_with_shared_strings(): void
    {
        $path = $this->makeXlsx([
            ['Автомобиль', 'VIN', 'Гос. номер', 'Год выпуска'],
            ['Toyota Camry', 'LVUDB21B2RA042817', 'А123АА178', '2020'],
        ], useSharedStrings: true);

        $rows = $this->importer->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('LVUDB21B2RA042817', $rows[0]['vin']);
        $this->assertSame('Toyota', $rows[0]['make']);
        $this->assertSame('А123АА178', $rows[0]['plate']);
        $this->assertSame(2020, $rows[0]['year']);
    }

    public function test_read_xlsx_with_empty_shared_strings_is_handled(): void
    {
        // XLSX без sharedStrings.xml, но и без inline-строк — все cellValue вернут ''
        // Это проверяет ветку "readSharedStrings вернул []".
        // Наш обычный makeXlsx без sharedStrings уже это делает, но:
        $path = $this->makeXlsx([
            ['Автомобиль', 'VIN', 'Гос. номер', 'Год выпуска'],
            ['', '', '', ''],  // пустая строка, но с валидным header — она пропустится через isNonEmptyRow
            ['Toyota', 'LVUDB21B2RA042817', 'А123АА178', '2020'],
        ]);

        $rows = $this->importer->read($path);

        $this->assertCount(1, $rows);
    }
}
