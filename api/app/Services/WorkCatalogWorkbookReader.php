<?php

declare(strict_types=1);

namespace App\Services;

final class WorkCatalogWorkbookReader
{
    public function __construct(private readonly XlsxTableReader $reader)
    {
    }

    /**
     * @return array{
     *     works: array<int, array<string, mixed>>,
     *     norms: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>
     * }
     */
    public function read(string $path): array
    {
        $errors = [];
        $works = $this->readSheet(
            $path,
            WorkCatalogSchema::WORK_HEADERS,
            'Работы',
            $errors,
        );
        $norms = $this->readSheet(
            $path,
            WorkCatalogSchema::NORM_HEADERS,
            'Нормы времени',
            $errors,
        );

        return compact('works', 'norms', 'errors');
    }

    /**
     * @param array<string, string> $headers
     * @param array<int, array<string, mixed>> $errors
     *
     * @return array<int, array<string, mixed>>
     */
    private function readSheet(
        string $path,
        array $headers,
        string $sheet,
        array &$errors,
    ): array {
        $expected = array_map(
            static fn (string $header): string => XlsxTableReader::normalizeHeader($header),
            $headers,
        );
        $headerRow = null;
        $columns = [];
        $result = [];

        $requiredHeaders = array_map(
            static fn (string $header): array => [$header],
            array_values($headers),
        );

        foreach ($this->reader->rows($path, $requiredHeaders) as $rowNumber => $cells) {
            if ($headerRow === null) {
                $normalized = array_map(
                    static fn (string $value): string => XlsxTableReader::normalizeHeader($value),
                    $cells,
                );

                if (count(array_intersect($normalized, $expected)) !== count($expected)) {
                    continue;
                }

                $headerRow = (int) $rowNumber;
                foreach ($cells as $column => $value) {
                    $field = array_search(
                        XlsxTableReader::normalizeHeader($value),
                        $expected,
                        true,
                    );

                    if ($field !== false) {
                        $columns[(string) $field] = (int) $column;
                    }
                }

                continue;
            }

            $values = [];
            foreach ($headers as $field => $header) {
                $values[$field] = trim((string) ($cells[$columns[$field]] ?? ''));
            }

            if ($this->emptyRow($values)) {
                continue;
            }

            $values['_row'] = (int) $rowNumber;
            $values['_sheet'] = $sheet;
            $values['_cells'] = array_map(
                fn (int $column): string => $this->cell($column, (int) $rowNumber),
                $columns,
            );
            $result[] = $values;
        }

        if ($headerRow === null) {
            $errors[] = $this->error(
                $sheet,
                1,
                'A1',
                '',
                'Не найдена строка со всеми обязательными заголовками',
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cell(int $column, int $row): string
    {
        $letters = '';
        for ($number = $column + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $letters = chr(65 + (($number - 1) % 26)) . $letters;
        }

        return $letters . $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(
        string $sheet,
        int $row,
        string $cell,
        string $field,
        string $message,
    ): array {
        return compact('sheet', 'row', 'cell', 'field', 'message');
    }
}
