<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureJsonBody;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class EnsureJsonBodyTest extends TestCase
{
    #[DataProvider('validRequestsProvider')]
    public function test_valid_requests_are_passed_through(
        string $method,
        string $uri,
        ?string $contentType,
        string $content,
        bool $withFile = false
    ): void {
        $request = Request::create($uri, $method, [], [], [], [], $content);

        if ($contentType !== null) {
            $request->headers->set('Content-Type', $contentType);
        }

        if ($withFile) {
            $request->files->set('file', new UploadedFile(__FILE__, 'test.txt', 'text/plain', null, true));
        }

        $response = $this->invoke($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public static function validRequestsProvider(): array
    {
        return [
            'OPTIONS' => ['OPTIONS', '/api/anything', null, ''],
            'GET' => ['GET', '/api/anything', null, ''],
            'POST multipart' => ['POST', '/api/requests/1/attachments', 'multipart/form-data; boundary=x', ''],
            'POST with files' => ['POST', '/api/requests/1/attachments', 'application/json', '', true],
            'POST without body' => ['POST', '/api/requests', null, ''],
            'POST with JSON object' => ['POST', '/api/test', 'application/json', '{"foo":"bar"}'],
            'PATCH with JSON object' => ['PATCH', '/api/requests/1', 'application/json', '{"patch":{"description":"text"}}'],
            'POST with empty array []' => ['POST', '/api/test', 'application/json', '[]'],
            'PATCH with valid works array' => ['PATCH', '/api/requests/1', 'application/json', '{"patch":{"works":[{"name":"Work"}]}}'],
        ];
    }

    #[DataProvider('invalidRequestsProvider')]
    public function test_invalid_requests_are_rejected(
        string $method,
        string $contentType,
        string $content,
        int $expectedStatus,
        string $expectedErrorCode,
        ?string $expectedMessage = null
    ): void {
        $request = Request::create('/api/test', $method, [], [], [], ['CONTENT_TYPE' => $contentType], $content);

        try {
            $this->invoke($request);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame($expectedStatus, $e->status);
            $this->assertSame($expectedErrorCode, $e->errorCode);

            if ($expectedMessage !== null) {
                $this->assertSame($expectedMessage, $e->getMessage());
            }
        }
    }

    public static function invalidRequestsProvider(): array
    {
        return [
            'non-JSON content type' => [
                'POST',
                'text/plain',
                'hello',
                415,
                'JSON_REQUIRED',
            ],
            'broken JSON' => [
                'POST',
                'application/json',
                '{"foo":',
                400,
                'INVALID_JSON',
            ],
            'oversized body' => [
                'POST',
                'application/json',
                '{"data":"' . str_repeat('a', 1_048_577) . '"}',
                413,
                'PAYLOAD_TOO_LARGE',
            ],
            'patch is array' => [
                'PATCH',
                'application/json',
                '{"patch":[]}',
                422,
                'VALIDATION_ERROR',
                'patch должен быть JSON-объектом'
            ],
            'works is not array' => [
                'PATCH',
                'application/json',
                '{"patch":{"works":"not-an-array"}}',
                422,
                'VALIDATION_ERROR',
                'works должен быть JSON-массивом',
            ],
        ];
    }

    private function invoke(Request $request): Response
    {
        return (new EnsureJsonBody())->handle(
            $request,
            static fn (): Response => new Response('', 200),
        );
    }
}
