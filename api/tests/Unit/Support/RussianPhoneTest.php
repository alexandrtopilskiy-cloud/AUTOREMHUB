<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RussianPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RussianPhoneTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function normalizeProvider(): array
    {
        // Все равнозначные форматы ввода из ТЗ от 10.11.2026 → «+7 ххх ххх хх хх».
        return [
            '+7 ххх ххх хх хх'      => ['+7 999 123 45 67', '+7 999 123 45 67'],
            '+7хххххххххх'          => ['+79991234567', '+7 999 123 45 67'],
            '+7 (ххх) ххх хх хх'    => ['+7 (999) 123 45 67', '+7 999 123 45 67'],
            '+7(ххх) ххх-хх-хх'     => ['+7(999) 123-45-67', '+7 999 123 45 67'],
            '+7 ххх ххх-хх-хх'      => ['+7 999 123-45-67', '+7 999 123 45 67'],
            '8 ххх ххх хх хх'       => ['8 999 123 45 67', '+7 999 123 45 67'],
            '8хххххххххх'           => ['89991234567', '+7 999 123 45 67'],
            '8 (ххх) ххх хх хх'     => ['8 (999) 123 45 67', '+7 999 123 45 67'],
            '8(ххх) ххх-хх-хх'      => ['8(999) 123-45-67', '+7 999 123 45 67'],
            '8 ххх ххх-хх-хх'       => ['8 999 123-45-67', '+7 999 123 45 67'],
            'короткий'              => ['+7999123456', null],
            'длинный'               => ['+799912345678', null],
            'не 7 и не 8'           => ['+69991234567', null],
            '+8 не принимается'     => ['+89991234567', null],
            'пустая строка'         => ['', null],
            'только буквы'          => ['abc', null],
            'буквы внутри'          => ['+7 999 123 45 6x', null],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, RussianPhone::normalize($input));
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function formatProvider(): array
    {
        return [
            'без пробелов'     => ['+79991234567', '+7 999 123 45 67'],
            'с 8'              => ['89991234567',  '+7 999 123 45 67'],
            'уже в формате'    => ['+7 999 123 45 67', '+7 999 123 45 67'],
            'null'             => [null, ''],
            'невалидный'       => ['abc', 'abc'],
            'короткий'         => ['+7999', '+7999'],
        ];
    }

    #[DataProvider('formatProvider')]
    public function test_format(?string $input, string $expected): void
    {
        $this->assertSame($expected, RussianPhone::format($input));
    }

    public function test_normalize_returns_null_for_non_digits(): void
    {
        $this->assertNull(RussianPhone::normalize('no phone here'));
    }

    public function test_format_returns_input_for_invalid(): void
    {
        $this->assertSame('invalid', RussianPhone::format('invalid'));
    }
}
