<?php
declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

final class ApiInput
{
    /**
     * Проверяет, что в JSON-теле запроса нет полей, кроме перечисленных.
     *
     * @param array<int, string> $allowedKeys
     *
     * @throws ApiException
     */
    public static function only(Request $request, array $allowedKeys): void
    {
        // Query parameters are not writable JSON attributes.
        $body = $request->json()->all();

        $unknown = array_diff(array_keys($body), $allowedKeys);

        if ($unknown !== []) {
            throw new ApiException(
                422,
                'VALIDATION_ERROR',
                'Неизвестные поля: ' . implode(', ', $unknown),
            );
        }
    }

    /**
     * Возвращает номер ревизии из тела запроса.
     *
     * @throws ApiException
     */
    public static function revision(Request $request): int
    {
        $revision = $request->json('revision');

        if (!is_int($revision) || $revision < 0) {
            throw new ApiException(
                422,
                'VALIDATION_ERROR',
                'revision должен быть неотрицательным целым числом',
                ['revision' => 'Некорректная ревизия'],
            );
        }

        return $revision;
    }

    /**
     * Возвращает объект patch из тела запроса.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public static function patch(Request $request): array
    {
        $patch = $request->json('patch');

        if (!is_array($patch)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект patch');
        }

        return $patch;
    }
}
