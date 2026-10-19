<?php
declare(strict_types=1);

namespace App\Support;

final class RussianPhone
{
    public static function normalize(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }

        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return null;
        }

        return '+' . $digits;
    }

    public static function format(?string $value): string
    {
        $normalized = self::normalize((string) $value);

        if ($normalized === null) {
            return (string) $value;
        }

        return sprintf(
            '+7 (%s) %s %s %s',
            substr($normalized, 2, 3),
            substr($normalized, 5, 3),
            substr($normalized, 8, 2),
            substr($normalized, 10, 2),
        );
    }
}
