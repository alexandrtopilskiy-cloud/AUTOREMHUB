<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DomainInput as Input;
use Generator;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

final class XlsxTableReader
{
    private const MAX_FILE_SIZE_BYTES = 30 * 1024 * 1024;

    private const MAX_XML_SIZE_BYTES = 128 * 1024 * 1024;

    private const HEADER_SCAN_ROWS = 200;

    /**
     * Возвращает строки первого листа, в котором найдены все обязательные
     * заголовки. Каждый элемент `$requiredHeaders` содержит допустимые
     * варианты одного заголовка.
     *
     * @param array<int, array<int, string>> $requiredHeaders
     *
     * @return array<int, array<int, string>>
     */
    public function read(string $path, array $requiredHeaders): array
    {
        return iterator_to_array($this->rows($path, $requiredHeaders), false);
    }

    /**
     * Потоковое чтение не загружает XML-лист целиком в память и поддерживает
     * большие справочники марок и моделей.
     *
     * @param array<int, array<int, string>> $requiredHeaders
     *
     * @return Generator<int, array<int, string>>
     */
    public function rows(string $path, array $requiredHeaders): Generator
    {
        $this->assertReadable($path);
        $path = (string) realpath($path);
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            Input::fail('Не удалось открыть XLSX');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheets = $this->sheetEntries($zip);
        } finally {
            $zip->close();
        }

        foreach ($sheets as $sheet) {
            $buffer = [];
            $headerFound = false;

            foreach ($this->parseSheet($path, $sheet, $sharedStrings) as $rowNumber => $row) {
                if ($headerFound) {
                    yield $rowNumber => $row;

                    continue;
                }

                $buffer[$rowNumber] = $row;

                if ($this->hasHeader([$row], $requiredHeaders)) {
                    $headerFound = true;

                    foreach ($buffer as $number => $bufferedRow) {
                        yield $number => $bufferedRow;
                    }

                    $buffer = [];

                    continue;
                }

                if (count($buffer) >= self::HEADER_SCAN_ROWS) {
                    break;
                }
            }

            if ($headerFound) {
                return;
            }
        }

        Input::fail('В XLSX не найден лист с обязательными колонками');
    }

    private function assertReadable(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            Input::fail('Файл не найден');
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE_BYTES) {
            Input::fail('Размер XLSX не должен превышать 30 МБ');
        }
    }

    /**
     * @return array<int, string>
     */
    private function sheetEntries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (!preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $name)) {
                continue;
            }

            $stat = $zip->statIndex($index);

            if ($stat === false || (int) $stat['size'] > self::MAX_XML_SIZE_BYTES) {
                Input::fail('Слишком большой XML-лист внутри XLSX');
            }

            $entries[] = $name;
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $content = $this->readZipEntry($zip, 'xl/sharedStrings.xml');

        if ($content === null) {
            return [];
        }

        $strings = [];

        foreach ($this->xml($content)->xpath('//*[local-name()="si"]') as $item) {
            $value = '';

            foreach ($item->xpath('.//*[local-name()="t"]') as $text) {
                $value .= (string) $text;
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param array<int, string> $sharedStrings
     *
     * @return Generator<int, array<int, string>> Номер строки XLSX => ячейки.
     */
    private function parseSheet(
        string $path,
        string $entry,
        array $sharedStrings,
    ): Generator {
        $reader = new XMLReader();
        $uri = 'zip://' . $path . '#' . $entry;

        if (!$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            Input::fail('Не удалось прочитать лист XLSX');
        }

        try {
            while ($reader->read()) {
                if (
                    $reader->nodeType !== XMLReader::ELEMENT
                    || $reader->localName !== 'row'
                ) {
                    continue;
                }

                $rowNumber = max(1, (int) $reader->getAttribute('r'));
                $xml = $reader->readOuterXml();

                if ($xml !== '') {
                    yield $rowNumber => $this->parseRow($xml, $sharedStrings);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param array<int, string> $sharedStrings
     *
     * @return array<int, string>
     */
    private function parseRow(string $content, array $sharedStrings): array
    {
        $row = $this->xml($content);
        $values = [];

        foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
            $column = preg_replace('/\d/', '', (string) $cell['r']);
            $values[$this->columnIndex((string) $column)] = $this->cellValue(
                $cell,
                $sharedStrings,
            );
        }

        return $values;
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function cellValue(
        SimpleXMLElement $cell,
        array $sharedStrings,
    ): string {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            $value = '';

            foreach ($cell->xpath('.//*[local-name()="t"]') as $text) {
                $value .= (string) $text;
            }

            return $value;
        }

        $nodes = $cell->xpath('./*[local-name()="v"]');
        $value = isset($nodes[0]) ? (string) $nodes[0] : '';

        return $type === 's' ? ($sharedStrings[(int) $value] ?? '') : $value;
    }

    private function columnIndex(string $letters): int
    {
        $column = 0;

        foreach (str_split(strtoupper($letters)) as $letter) {
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

        if ((int) $stat['size'] > self::MAX_XML_SIZE_BYTES) {
            Input::fail('Слишком большой XML-файл внутри XLSX');
        }

        $content = $zip->getFromName($name);

        return $content === false ? null : $content;
    }

    private function xml(string $content): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string(
                $content,
                SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_COMPACT,
            );

            if ($xml === false) {
                Input::fail('Некорректный XML внутри XLSX');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<int, array<int, string>> $requiredHeaders
     */
    private function hasHeader(array $rows, array $requiredHeaders): bool
    {
        foreach ($rows as $row) {
            $values = array_map(
                static fn (string $value): string => self::normalizeHeader($value),
                $row,
            );

            $matches = true;

            foreach ($requiredHeaders as $aliases) {
                $normalizedAliases = array_map(
                    static fn (string $value): string => self::normalizeHeader($value),
                    $aliases,
                );

                if (array_intersect($values, $normalizedAliases) === []) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeHeader(string $value): string
    {
        $value = ltrim($value, "\xEF\xBB\xBF");

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
