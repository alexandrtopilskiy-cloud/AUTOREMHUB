<?php
declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

final class DomainInput
{
    /**
     * Допустимая погрешность для decimal-проверки.
     */
    private const DECIMAL_EPSILON = 0.00000001;

    /**
     * Кастомные сообщения валидации.
     *
     * @var array<string, string>
     */
    private const VALIDATION_MESSAGES = [
        'required' => 'Заполните :attribute',
        'string'   => ':attribute должно быть строкой',
        'numeric'  => ':attribute должно быть числом',
        'uuid'     => 'Некорректный UUID :attribute',
    ];

    /**
     * Валидирует данные, предварительно проверив отсутствие лишних полей.
     *
     * @param array<string, mixed>             $data
     * @param array<string, array<int, mixed>> $rules
     * @param array<string, string>            $messages
     * @param array<string, string>            $attributes
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public static function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = [],
    ): array {
        $allowed = self::topLevelKeys($rules);
        $extra = array_diff(array_keys($data), $allowed);

        if ($extra !== []) {
            self::fail('Неизвестные поля: ' . implode(', ', $extra));
        }

        return Validator::make(
            $data,
            $rules,
            array_merge(self::VALIDATION_MESSAGES, $messages),
            $attributes,
        )->validate();
    }

    /**
     * @throws ApiException
     */
    public static function fail(string $message, string $field = ''): never
    {
        throw new ApiException(
            422,
            'VALIDATION_ERROR',
            $message,
            $field !== '' ? [$field => $message] : [],
        );
    }

    /**
     * Правило валидации UUID, допускающее пустую строку.
     *
     * @return array<int, mixed>
     */
    public static function uuidOrEmpty(): array
    {
        return [
            'bail',
            'sometimes',
            'string',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== '' && !Str::isUuid($value)) {
                    $fail('Некорректный UUID');
                }
            },
        ];
    }

    /**
     * Разбирает локальное время в UTC. Пустая строка → null.
     *
     * @throws ApiException
     */
    public static function localTime(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                $value,
                config('sto.service_timezone'),
            );
        } catch (Throwable) {
            self::fail('Время должно иметь формат YYYY-MM-DDTHH:mm');
        }

        if ($date === null || $date->format('Y-m-d\TH:i') !== $value) {
            self::fail('Некорректная дата');
        }

        if ((int) $date->format('i') % 30 !== 0) {
            self::fail('Время выбирается с интервалом 30 минут');
        }

        return $date->utc();
    }

    /**
     * Правило валидации для JSON-числа с ограниченной точностью.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function numeric(int $scale, ?string $message = null): Closure
    {
        return static function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ) use ($scale, $message): void {
            if ($value === null || $value === '') {
                return;
            }

            $isNumber = is_int($value) || is_float($value);

            if (!$isNumber || !is_finite((float) $value)) {
                $fail($message ?? self::decimalMessage($scale));

                return;
            }

            $deviation = abs($value - round($value, $scale));

            if ($deviation > self::DECIMAL_EPSILON) {
                $fail($message ?? self::decimalMessage($scale));
            }
        };
    }

    /* ─────────────────── Приватные хелперы ─────────────────── */

    /**
     * Возвращает верхнеуровневые ключи из правил (до первой точки).
     *
     * @param array<string, mixed> $rules
     *
     * @return array<int, string>
     */
    private static function topLevelKeys(array $rules): array
    {
        $keys = [];

        foreach (array_keys($rules) as $key) {
            $keys[] = explode('.', $key)[0];
        }

        return array_values(array_unique($keys));
    }

    private static function decimalMessage(int $scale): string
    {
        return 'Нужно JSON-число, не более ' . $scale . ' знаков после запятой';
    }
}
