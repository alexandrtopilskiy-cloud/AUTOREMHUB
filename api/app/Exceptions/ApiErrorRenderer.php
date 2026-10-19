<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrorRenderer
{
    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, string>, 4: array<string, string>}
     */
    public static function resolve(Throwable $e, Request $request): array
    {
        $status = 500;
        $code = 'SERVER_ERROR';
        $message = 'Внутренняя ошибка API';
        $fields = [];
        $headers = [];

        if ($e instanceof ApiException) {
            [$status, $code, $message, $fields] = [
                $e->status,
                $e->errorCode,
                $e->getMessage(),
                $e->fields,
            ];
        } elseif ($e instanceof ValidationException) {
            $status = 422;
            $code = 'VALIDATION_ERROR';
            $fields = array_map(
                static fn (array $messages): string => $messages[0],
                $e->errors(),
            );
            $message = reset($fields) ?: 'Проверьте заполнение полей';
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $code = 'UNAUTHENTICATED';
            $message = 'Войдите в личный кабинет';
        } elseif ($e instanceof ModelNotFoundException) {
            $status = 404;
            $code = 'NOT_FOUND';
            $message = 'Запись не найдена';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();

            [$code, $message] = match ($status) {
                403     => ['FORBIDDEN', 'Недостаточно прав'],
                404     => ['NOT_FOUND', 'Запись не найдена'],
                405     => ['METHOD_NOT_ALLOWED', 'Метод не поддерживается'],
                413     => ['PAYLOAD_TOO_LARGE', 'Слишком большой запрос'],
                429     => ['RATE_LIMITED', 'Слишком много запросов. Повторите позднее'],
                503     => ['UNAVAILABLE', 'API временно недоступен'],
                default => ['HTTP_ERROR', 'Не удалось выполнить запрос'],
            };
        }

        return [$status, $code, $message, $fields, $headers];
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $fields, $headers] = self::resolve($e, $request);

        $error = [
            'code'    => $code,
            'message' => $message,
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return response()->json(['error' => $error], $status, $headers);
    }
}
