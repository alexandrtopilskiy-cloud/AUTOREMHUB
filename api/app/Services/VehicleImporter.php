<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Vehicle;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use ZipArchive;

final class VehicleImporter
{
    private const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024;
    private const MAX_SHEET_SIZE_BYTES = 16 * 1024 * 1024;
    private const MAX_ROWS = 10_000;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_PLATE_LENGTH = 30;
    private const MIN_YEAR = 1900;
    private const MAX_YEAR = 2100;
    private const VIN_PATTERN = '/^[A-HJ-NPR-Z0-9]{17}$/';
    private const YEAR_PATTERN = '/^\d{4}$/';
    private const MILEAGE_PATTERN = '/^\d{1,9}$/';
    private const REQUIRED_COLUMNS_MESSAGE = 'Ожидаются столбцы: Автомобиль, VIN, Гос. номер, Год выпуска';

    /**
     * Прочитать файл XLSX/CSV и вернуть нормализованные строки.
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(string $path): array
    {
        $this->assertFileReadable($path);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $rows = match ($extension) {
            'xlsx'  => $this->xlsx($path),
            'csv'   => $this->csv($path),
            default => Input::fail('Поддерживаются XLSX и CSV'),
        };

        return $this->normalizeRows($rows);
    }

    /**
     * Импортировать автомобили в организацию.
     *
     * @return array{created: int, updated: int, total: int, dryRun: bool}
     */
    public function import(Organization $organization, string $path, bool $dryRun = false): array
    {
        if ($organization->kind !== OrganizationKind::Customer) {
            Input::fail('Автомобили импортируются в организацию Заказчика');
        }

        $rows = $this->read($path);

        return DB::transaction(function () use ($organization, $rows, $dryRun): array {
            Organization::query()
            ->whereKey($organization->id)
                ->lockForUpdate()
                ->firstOrFail();

            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $existing = Vehicle::query()
                ->where('vin', $row['vin'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && (int) $existing->organization_id !== (int) $organization->id) {
                    Input::fail(
                        'VIN ' . $row['vin']
                        . ' уже принадлежит другой организации. Перенос автоматически не выполняется'
                    );
                }

                if ($existing !== null) {
                    $updated++;
                } else {
                    $created++;
                }

                if ($dryRun) {
                    continue;
                }

                $vehicle = $existing ?? new Vehicle([
                    'organization_id' => $organization->id,
                    'tenant_id'       => $organization->tenant_id,
                ]);

                $vehicle->fill($row);
                $vehicle->save();
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'total'   => count($rows),
                'dryRun'  => $dryRun,
            ];
        });
    }

    /* ─────────────────── Чтение файлов ─────────────────── */

    private function assertFileReadable(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            Input::fail('Файл не найден');
        }

        if (filesize($path) > self::MAX_FILE_SIZE_BYTES) {
            Input::fail('Размер файла импорта не должен превышать 20 МБ');
        }
    }

    /**
     * Прочитать CSV в массив строк.
     *
     * @return array<int, array<int, string|null>>
     */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            Input::fail('Не удалось открыть CSV');
        }

        try {
            $firstLine = fgets($handle);

            if ($firstLine === false) {
                return [];
            }

            rewind($handle);

            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

            $rows = [];

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Прочитать XLSX и вернуть строки листа с колонкой VIN.
     *
     * @return array<int, array<int, string>>
     */
    private function xlsx(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            Input::fail('Не удалось открыть XLSX');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (!preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', (string) $name)) {
                    continue;
                }

                $rows = $this->parseSheet($this->readZipEntry($zip, (string) $name), $sharedStrings);

                foreach ($rows as $row) {
                    if (in_array('VIN', array_map('strtoupper', $row), true)) {
                        return $rows;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        Input::fail('В XLSX не найден лист с колонкой VIN');
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $this->readZipEntry($zip, 'xl/sharedStrings.xml');

        if ($xml === null) {
            return [];
        }

        $strings = [];

        foreach ($this->xml($xml)->xpath('//*[local-name()="si"]') as $si) {
            $text = '';

            foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string) $t;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param array<int, string> $sharedStrings
     *
     * @return array<int, array<int, string>>
     */
    private function parseSheet(string $xmlContent, array $sharedStrings): array
    {
        $rows = [];

        foreach ($this->xml($xmlContent)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $values = [];

            foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                $columnIndex = $this->columnIndexFromLetters(
                    preg_replace('/\d/', '', (string) $cell['r'])
                );

                $values[$columnIndex] = $this->cellValue($cell, $sharedStrings);
            }

            $rows[] = $values;
        }

        return $rows;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            $text = '';

            foreach ($cell->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string) $t;
            }

            return $text;
        }

        $v = $cell->xpath('./*[local-name()="v"]');
        $value = isset($v[0]) ? (string) $v[0] : '';

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        return $value;
    }

    private function columnIndexFromLetters(string $letters): int
    {
        $column = 0;

        foreach (str_split($letters) as $letter) {
            $column = $column * 26 + ord($letter) - 64;
        }

        return $column - 1;
    }

    private function readZipEntry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            return null;
        }

        if ($stat['size'] > self::MAX_SHEET_SIZE_BYTES) {
            Input::fail('Слишком большой лист XLSX');
        }

        $content = $zip->getFromName($name);

        return $content === false ? null : $content;
    }

    private function xml(string $data): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET);

            if ($xml === false) {
                Input::fail('Некорректный XML внутри XLSX');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /* ─────────────────── Нормализация ─────────────────── */

    /**
     * @param array<int, array<int, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $result = [];
        $header = null;
        $seenVins = [];
        $sawNonEmptyRow = false;

        foreach ($rows as $index => $row) {
            if ($header === null) {
                $header = $this->detectHeader($row);

                if ($header !== null) {
                    continue;
                }

                if ($this->isNonEmptyRow($row)) {
                    $sawNonEmptyRow = true;
                }

                continue;
            }

            if (!$this->isNonEmptyRow($row)) {
                continue;
            }

            $item = $this->normalizeRow($row, $header, $index, $seenVins);

            if ($item !== null) {
                $result[] = $item;
            }
        }

        if ($result === []) {
            if ($sawNonEmptyRow) {
                Input::fail('Не найден столбец VIN');
            }

            Input::fail('В файле не найдено автомобилей');
        }

        if (count($result) > self::MAX_ROWS) {
            Input::fail('Импортируйте не более 10 000 автомобилей за один раз');
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $row
     *
     * @return array<string, int|null>|null
     */
    private function detectHeader(array $row): ?array
    {
        $normalized = array_map(
            static fn ($value) => mb_strtolower(trim(ltrim((string) $value, "\xEF\xBB\xBF"))),
            $row,
        );

        $vin = array_search('vin', $normalized, true);

        if ($vin === false) {
            return null;
        }

        $header = [
            'vin'     => $vin,
            'name'    => $this->findColumn($normalized, ['автомобиль', 'vehicle']),
            'plate'   => $this->findColumn($normalized, ['гос. номер', 'госномер', 'plate']),
            'year'    => $this->findColumn($normalized, ['год выпуска', 'year']),
            'mileage' => $this->findColumn($normalized, [
                'пробег на прошлом то',
                'пробег прошлого то',
                'пробег предыдущего то',
                'previousservicemileage',
                'previous service mileage',
                'baseline_service_mileage',
            ]),
        ];

        if ($header['name'] === null || $header['plate'] === null || $header['year'] === null) {
            Input::fail(self::REQUIRED_COLUMNS_MESSAGE);
        }

        return $header;
    }

    /**
     * @param array<int, string>   $normalized
     * @param array<int, string>   $names
     */
    private function findColumn(array $normalized, array $names): ?int
    {
        foreach ($normalized as $index => $value) {
            if (in_array($value, $names, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function isNonEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed>          $row
     * @param array<string, int|null>    $header
     * @param array<string, bool>        $seenVins
     *
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, array $header, int $index, array &$seenVins): ?array
    {
        $vin = strtoupper(trim((string) ($row[$header['vin']] ?? '')));

        if (!preg_match(self::VIN_PATTERN, $vin)) {
            Input::fail('Строка ' . ($index + 1) . ': VIN должен содержать 17 допустимых символов');
        }

        if (isset($seenVins[$vin])) {
            Input::fail('Повтор VIN в файле: ' . $vin);
        }

        $seenVins[$vin] = true;

        $name = trim((string) ($row[$header['name']] ?? ''));
        $year = trim((string) ($row[$header['year']] ?? ''));

        if (
            $name === ''
            || mb_strlen($name) > self::MAX_NAME_LENGTH
            || !preg_match(self::YEAR_PATTERN, $year)
            || (int) $year < self::MIN_YEAR
            || (int) $year > self::MAX_YEAR
        ) {
            Input::fail('Проверьте модель и год в строке ' . ($index + 1));
        }

        $plate = mb_strtoupper(trim((string) ($row[$header['plate']] ?? '')));

        if (mb_strlen($plate) > self::MAX_PLATE_LENGTH) {
            Input::fail('Слишком длинный госномер');
        }

        [$make, $model] = array_pad(explode(' ', $name, 2), 2, '');

        $item = [
            'name'             => $name,
            'make'             => $make,
            'model'            => $model,
            'vin'              => $vin,
            'plate'            => $plate,
            'plate_normalized' => Vehicle::plateKey($plate),
            'year'             => (int) $year,
        ];

        $mileage = $this->extractMileage($row, $header);

        if ($mileage !== null) {
            $item['baseline_service_mileage'] = $mileage;
        }

        return $item;
    }

    /**
     * @param array<int, mixed>       $row
     * @param array<string, int|null> $header
     */
    private function extractMileage(array $row, array $header): ?int
    {
        if ($header['mileage'] === null) {
            return null;
        }

        $value = trim((string) ($row[$header['mileage']] ?? ''));

        if ($value === '') {
            return null;
        }

        if (!preg_match(self::MILEAGE_PATTERN, $value)) {
            Input::fail('Проверьте пробег на прошлом ТО');
        }

        return (int) $value;
    }
}
