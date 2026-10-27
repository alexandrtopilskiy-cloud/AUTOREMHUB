<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

final class EnsureJsonBody
{
    private const MAX_PAYLOAD_BYTES = 1_048_576; // 1 МБ

    public function handle(Request $request, Closure $next): Response
    {
        // 1. OPTIONS всегда пропускаем
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        // 2. Загрузка файлов (multipart) пропускаем
        if ($this->isMultipartUpload($request)) {
            return $next($request);
        }

        // 3. Проверяем только POST/PATCH с непустым телом
        if ($this->requiresJsonValidation($request)) {
            $this->validateJsonBody($request);
        }

        return $next($request);
    }

    private function isMultipartUpload(Request $request): bool
    {
        if (!$request->isMethod('POST')) {
            return false;
        }

        $contentType = strtolower((string) $request->header('Content-Type'));
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return true;
        }

        // Страховка: если Content-Type переопределен, но файлы реально пришли
        return $request->files->count() > 0;
    }

    private function requiresJsonValidation(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PATCH'], true)
        && $request->getContent() !== '';
    }

    private function validateJsonBody(Request $request): void
    {
        if (!$request->isJson()) {
            throw new ApiException(415, 'JSON_REQUIRED', 'Отправьте Content-Type: application/json');
        }

        $content = $request->getContent();

        if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'Запрос превышает 1 МБ');
        }

        $body = $this->decodeJson($content);

        // Разрешаем пустой массив [] — эквивалент пустого тела
        if ($body === []) {
            return;
        }

        if (!$body instanceof stdClass) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Тело запроса должно быть JSON-объектом');
        }

        $this->validatePatch($body);
    }

    private function decodeJson(string $content): mixed
    {
        try {
            return json_decode($content, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'Некорректный JSON');
        }
    }

    private function validatePatch(stdClass $body): void
    {
        if (!property_exists($body, 'patch')) {
            return;
        }

        if (!$body->patch instanceof stdClass) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'patch должен быть JSON-объектом');
        }

        if (property_exists($body->patch, 'works') && !is_array($body->patch->works)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'works должен быть JSON-массивом');
        }
    }
}
