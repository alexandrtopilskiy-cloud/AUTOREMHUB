<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureJsonBody;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Unit-тест для EnsureJsonBody.
 *
 * Проверяет все ветки middleware:
 *   - OPTIONS              → пропуск
 *   - multipart/form-data  → пропуск
 *   - POST /api/requests   → пропуск (без тела)
 *   - POST/PATCH с JSON-объектом → пропуск
 *   - POST с JSON-массивом → 422
 *   - POST с не-JSON       → 415
 *   - PATCH с patch-массивом → 422
 *   - PATCH с patch-объектом → пропуск
 *   - битый JSON           → 400
 *   - тело > 1 МБ          → 413
 *   - GET                  → пропуск (только POST/PATCH)
 */
final class EnsureJsonBodyTest extends TestCase
{
    public function test_options_request_is_passed_through(): void
    {
        $request = Request::create('/api/anything', 'OPTIONS');

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_multipart_post_is_passed_through(): void
    {
        $request = Request::create('/api/requests/123/attachments', 'POST');
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----x');

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_files_is_passed_through(): void
    {
        $request = Request::create('/api/requests/123/attachments', 'POST');
        $request->headers->set('Content-Type', 'application/json');
        $request->files->set('file', new \Symfony\Component\HttpFoundation\File\UploadedFile(
            __FILE__,
            'test.txt',
            'text/plain',
            null,
            true,
        ));

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_to_api_requests_without_body_is_passed_through(): void
    {
        $request = Request::create('/api/requests', 'POST');

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_json_object_is_passed_through(): void
    {
        $request = $this->jsonRequest('/api/test', 'POST', ['foo' => 'bar']);

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_patch_with_json_object_is_passed_through(): void
    {
        $request = $this->jsonRequest('/api/requests/1', 'PATCH', [
            'patch' => ['description' => 'text'],
        ]);

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_get_request_is_passed_through(): void
    {
        $request = Request::create('/api/anything', 'GET');

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_json_array_is_rejected_with_422(): void
    {
        $request = Request::create(
            '/api/test',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '[]',
        );

        try {
            $this->invoke($request);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('VALIDATION_ERROR', $e->errorCode);
            $this->assertSame('Тело запроса должно быть JSON-объектом', $e->getMessage());
        }
    }

    public function test_post_with_non_json_content_type_is_rejected_with_415(): void
    {
        $request = Request::create(
            '/api/test',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'hello',
        );

        try {
            $this->invoke($request);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(415, $e->status);
            $this->assertSame('JSON_REQUIRED', $e->errorCode);
        }
    }

    public function test_broken_json_is_rejected_with_400(): void
    {
        $request = Request::create(
            '/api/test',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"foo":',
        );

        try {
            $this->invoke($request);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('INVALID_JSON', $e->errorCode);
        }
    }

    public function test_oversized_body_is_rejected_with_413(): void
    {
        $big = str_repeat('a', 1_048_577);
        $request = Request::create(
            '/api/test',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"data":"' . $big . '"}',
        );

        try {
            $this->invoke($request);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(413, $e->status);
            $this->assertSame('PAYLOAD_TOO_LARGE', $e->errorCode);
        }
    }

    public function test_patch_with_array_patch_is_rejected_with_422(): void
    {
        $request = Request::create(
            '/api/requests/1',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"patch":[]}',
        );

        try {
            $this->invoke($request);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('patch должен быть JSON-объектом', $e->getMessage());
        }
    }

    public function test_patch_with_non_array_works_is_rejected_with_422(): void
    {
        $request = Request::create(
            '/api/requests/1',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"patch":{"works":"not-an-array"}}',
        );

        try {
            $this->invoke($request);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('works должен быть JSON-массивом', $e->getMessage());
        }
    }

    public function test_patch_with_works_array_is_passed_through(): void
    {
        $request = $this->jsonRequest('/api/requests/1', 'PATCH', [
            'patch' => ['works' => [['name' => 'Работа']]],
        ]);

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /* ─────────────────── helpers ─────────────────── */

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $uri, string $method, array $body): Request
    {
        return Request::create(
            $uri,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    private function invoke(Request $request): Response
    {
        $middleware = new EnsureJsonBody();

        return $middleware->handle(
            $request,
            static fn (): Response => new Response('', 200),
        );
    }
}
