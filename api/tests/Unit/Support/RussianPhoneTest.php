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
        return [
            'уже нормализован'      => ['+79991234567', '+79991234567'],
            'с 8 в начале'          => ['89991234567',  '+79991234567'],
            'с пробелами'           => ['+7 999 123 45 67', '+79991234567'],
            'со скобками и дефисом' => ['+7 (999) 123-45-67', '+79991234567'],
            '8 с пробелами'         => ['8 999 123 45 67', '+79991234567'],
            '8 со скобками'         => ['8 (999) 123-45-67', '+79991234567'],
            'короткий'              => ['+7999123456', null],
            'длинный'               => ['+799912345678', null],
            'не 7 и не 8'           => ['+69991234567', null],
            'пустая строка'         => ['', null],
            'только буквы'          => ['abc', null],
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
            'уже нормализован' => ['+79991234567', '+7 (999) 123 45 67'],
            'с 8'              => ['89991234567',  '+7 (999) 123 45 67'],
            'с пробелами'      => ['+7 999 123 45 67', '+7 (999) 123 45 67'],
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
