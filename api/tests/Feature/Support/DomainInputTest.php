<?php
declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\ApiException;
use App\Support\DomainInput as Input;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DomainInput::validate() бросает:
 *   - ApiException        — при extra-полях (до Validator)
 *   - ValidationException — при невалидных данных (из Laravel Validator)
 *
 * На HTTP-уровне ValidationException конвертируется в ApiException
 * через ApiErrorRenderer, но на unit-уровне — нет.
 */
final class DomainInputTest extends TestCase
{
    /* ─────────────────── validate ─────────────────── */

    public function test_validate_passes_with_valid_data(): void
    {
        $result = Input::validate(
            ['name' => 'Иван', 'age' => 30],
            [
                'name' => ['required', 'string'],
                'age'  => ['required', 'integer'],
            ],
        );

        $this->assertSame(['name' => 'Иван', 'age' => 30], $result);
    }

    public function test_validate_throws_api_exception_on_extra_fields(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Неизвестные поля: extra');

        Input::validate(
            ['name' => 'Иван', 'extra' => 'oops'],
            ['name' => ['required', 'string']],
        );
    }

    public function test_validate_ignores_nested_keys_in_allowed_list(): void
    {
        $result = Input::validate(
            ['items' => [['name' => 'A'], ['name' => 'B']]],
            ['items' => ['required', 'array'], 'items.*.name' => ['required', 'string']],
        );

        $this->assertCount(2, $result['items']);
    }

    public function test_validate_throws_validation_exception_on_invalid_data(): void
    {
        $this->expectException(ValidationException::class);

        Input::validate(
            ['name' => ''],
            ['name' => ['required', 'string']],
        );
    }

    public function test_validate_uses_custom_messages(): void
    {
        try {
            Input::validate(
                ['name' => ''],
                ['name' => ['required', 'string']],
                ['required' => 'Нужно :attribute'],
                ['name' => 'Имя'],
            );
            $this->fail('Ожидалось ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Нужно Имя', $e->getMessage());
        }
    }

    /* ─────────────────── fail ─────────────────── */

    public function test_fail_throws_api_exception(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Тестовая ошибка');

        Input::fail('Тестовая ошибка');
    }

    public function test_fail_attaches_field_error(): void
    {
        try {
            Input::fail('Ошибка поля', 'field');
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(['field' => 'Ошибка поля'], $e->fields);
        }
    }

    /* ─────────────────── uuidOrEmpty ─────────────────── */

    public function test_uuid_or_empty_returns_array(): void
    {
        $rules = Input::uuidOrEmpty();

        $this->assertIsArray($rules);
        $this->assertContains('bail', $rules);
        $this->assertContains('sometimes', $rules);
        $this->assertContains('string', $rules);
    }

    public function test_uuid_or_empty_accepts_valid_uuid(): void
    {
        $validator = validator(
            ['id' => '28b677ae-a3d6-4c72-99e9-dd65cf5f85ca'],
            ['id' => Input::uuidOrEmpty()],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_uuid_or_empty_accepts_empty_string(): void
    {
        $validator = validator(
            ['id' => ''],
            ['id' => Input::uuidOrEmpty()],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_uuid_or_empty_rejects_invalid_uuid(): void
    {
        $validator = validator(
            ['id' => 'not-a-uuid'],
            ['id' => Input::uuidOrEmpty()],
        );

        $this->assertTrue($validator->fails());
    }

    /* ─────────────────── localTime ─────────────────── */

    public function test_local_time_returns_null_for_empty(): void
    {
        $this->assertNull(Input::localTime(''));
    }

    public function test_local_time_parses_valid_string(): void
    {
        $result = Input::localTime('2026-10-20T11:00');

        $this->assertNotNull($result);
        $this->assertSame('2026-10-20 08:00', $result->utc()->format('Y-m-d H:i'));
    }

    public function test_local_time_throws_on_wrong_format(): void
    {
        $this->expectException(ApiException::class);

        Input::localTime('2026-10-20');
    }

    public function test_local_time_throws_on_non_half_hour(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Время выбирается с интервалом 30 минут');

        Input::localTime('2026-10-20T11:15');
    }

    /* ─────────────────── numeric ─────────────────── */

    public function test_numeric_accepts_integer(): void
    {
        $validator = validator(['x' => 100], ['x' => Input::numeric(2)]);

        $this->assertFalse($validator->fails());
    }

    public function test_numeric_accepts_float_with_scale(): void
    {
        $validator = validator(['x' => 99.99], ['x' => Input::numeric(2)]);

        $this->assertFalse($validator->fails());
    }

    public function test_numeric_rejects_too_many_decimals(): void
    {
        $validator = validator(['x' => 99.999], ['x' => Input::numeric(2)]);

        $this->assertTrue($validator->fails());
    }

    public function test_numeric_rejects_string(): void
    {
        $validator = validator(['x' => '100'], ['x' => Input::numeric(2)]);

        $this->assertTrue($validator->fails());
    }

    public function test_numeric_uses_custom_message(): void
    {
        $validator = validator(
            ['x' => 99.999],
            ['x' => Input::numeric(2, 'Кастомное сообщение')],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'Кастомное сообщение',
            $validator->errors()->first('x'),
        );
    }
}