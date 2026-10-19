<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Exceptions\ApiException;
use App\Support\ApiInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ApiInputTest extends TestCase
{
    public function test_only_passes_when_no_extra_fields(): void
    {
        $request = $this->jsonRequest(['revision' => 1, 'patch' => []]);

        ApiInput::only($request, ['revision', 'patch']);

        $this->addToAssertionCount(1);
    }

    public function test_only_throws_on_unknown_fields(): void
    {
        $request = $this->jsonRequest([
            'revision' => 1,
            'patch'    => [],
            'extra'    => 'oops',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Неизвестные поля: extra');

        ApiInput::only($request, ['revision', 'patch']);
    }

    public function test_only_throws_on_multiple_unknown_fields(): void
    {
        $request = $this->jsonRequest([
            'revision' => 1,
            'foo'      => 1,
            'bar'      => 2,
        ]);

        try {
            ApiInput::only($request, ['revision']);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('foo', $e->getMessage());
            $this->assertStringContainsString('bar', $e->getMessage());
        }
    }

    public function test_revision_returns_positive_int(): void
    {
        $request = $this->jsonRequest(['revision' => 42]);

        $this->assertSame(42, ApiInput::revision($request));
    }

    public function test_revision_accepts_zero(): void
    {
        $request = $this->jsonRequest(['revision' => 0]);

        $this->assertSame(0, ApiInput::revision($request));
    }

    public function test_revision_throws_on_negative(): void
    {
        $request = $this->jsonRequest(['revision' => -1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('revision должен быть неотрицательным целым числом');

        ApiInput::revision($request);
    }

    public function test_revision_throws_on_string(): void
    {
        $request = $this->jsonRequest(['revision' => '5']);

        $this->expectException(ApiException::class);

        ApiInput::revision($request);
    }

    public function test_revision_throws_on_missing(): void
    {
        $request = $this->jsonRequest([]);

        $this->expectException(ApiException::class);

        ApiInput::revision($request);
    }

    public function test_patch_returns_array(): void
    {
        $request = $this->jsonRequest(['patch' => ['description' => 'text']]);

        $this->assertSame(['description' => 'text'], ApiInput::patch($request));
    }

    public function test_patch_accepts_empty_array(): void
    {
        $request = $this->jsonRequest(['patch' => []]);

        $this->assertSame([], ApiInput::patch($request));
    }

    public function test_patch_throws_when_missing(): void
    {
        $request = $this->jsonRequest([]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Передайте объект patch');

        ApiInput::patch($request);
    }

    public function test_patch_throws_when_not_array(): void
    {
        $request = $this->jsonRequest(['patch' => 'string']);

        $this->expectException(ApiException::class);

        ApiInput::patch($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $request = Request::create(
            '/test',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );

        return $request;
    }
}
