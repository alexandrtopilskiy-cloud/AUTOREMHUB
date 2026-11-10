<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Телефон РФ. Ввод принимается в любом из равнозначных форматов
 * (+7 ххх ххх хх хх, +7хххххххххх, +7 (ххх) ххх хх хх, +7(ххх) ххх-хх-хх,
 * 8 ххх ххх хх хх, 8хххххххххх, 8 (ххх) ххх-хх-хх …), в базе данных
 * хранится единообразно: «+7 ххх ххх хх хх».
 */
final class RussianPhone
{
    /** Формат хранения в базе данных. */
    public const PATTERN = '/^\+7 [0-9]{3} [0-9]{3} [0-9]{2} [0-9]{2}$/';

    /** То же для CHECK-ограничений PostgreSQL. */
    public const SQL_PATTERN = '^\+7 [0-9]{3} [0-9]{3} [0-9]{2} [0-9]{2}$';

    public const HINT = '+7 ххх ххх хх хх';

    /** Приводит ввод к формату хранения; null — номер не распознан. */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        // Допускаются только цифры, пробелы, скобки, дефисы и ведущий «+».
        if ($value === '' || !preg_match('/^\+?[0-9\s()\-]+$/u', $value)) {
            return null;
        }
        if (str_starts_with($value, '+') && !str_starts_with($value, '+7')) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) === 11 && $digits[0] === '8' && !str_starts_with($value, '+')) {
            $digits = '7' . substr($digits, 1);
        }

        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return null;
        }

        return sprintf(
            '+7 %s %s %s %s',
            substr($digits, 1, 3),
            substr($digits, 4, 3),
            substr($digits, 7, 2),
            substr($digits, 9, 2),
        );
    }

    /** Телефон для показа: в формате хранения, нераспознанный — как есть. */
    public static function format(?string $value): string
    {
        return self::normalize((string) $value) ?? (string) $value;
    }
}
