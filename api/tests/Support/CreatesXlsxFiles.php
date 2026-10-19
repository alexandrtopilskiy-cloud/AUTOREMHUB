<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

trait CreatesXlsxFiles
{
    /** @var array<int, string> */
    private array $xlsxFiles = [];

    /**
     * @param array<int, array<int, scalar|null>> $rows
     */
    protected function createXlsxFile(array $rows, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный XLSX');
        }

        $xmlRows = '';

        foreach ($rows as $rowIndex => $row) {
            $cells = '';

            foreach ($row as $columnIndex => $value) {
                $reference = $this->xlsxColumnName($columnIndex + 1)
                    . ($rowIndex + 1);
                $escaped = htmlspecialchars(
                    (string) $value,
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8',
                );
                $cells .= '<c r="' . $reference . '" t="inlineStr">'
                    . '<is><t xml:space="preserve">' . $escaped
                    . '</t></is></c>';
            }

            $xmlRows .= '<row r="' . ($rowIndex + 1) . '">'
                . $cells . '</row>';
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Не удалось открыть временный XLSX');
        }

        try {
            $zip->addFromString(
                'xl/worksheets/sheet1.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet '
                . 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData>' . $xmlRows . '</sheetData></worksheet>',
            );
        } finally {
            $zip->close();
        }

        $this->xlsxFiles[] = $path;

        return $path;
    }

    protected function deleteXlsxFiles(): void
    {
        foreach ($this->xlsxFiles as $path) {
            @unlink($path);
        }

        $this->xlsxFiles = [];
    }

    private function xlsxColumnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }
}
