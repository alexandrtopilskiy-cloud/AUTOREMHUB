<?php

declare(strict_types=1);

namespace Tests\Feature\Edo\EdoRu;

use App\Exceptions\EdoException;
use App\Services\Edo\Providers\EdoRu\EdoRuClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EdoRuClientAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();

        config()->set('edo.providers.edoru.base_url', 'https://edoru.example.test/api/edo/v1');
        config()->set('edo.providers.edoru.auth_mode', 'auth-global');
        config()->set('edo.providers.edoru.client_id', '2PS-0078414651');
        config()->set('edo.providers.edoru.password', 'PASSWORD');
        config()->set('edo.providers.edoru.encoding', 'windows-1251');
    }

    public function test_get_resolves_token_via_auth_global_and_caches_it(): void
    {
        Http::fake([
            'edoru.example.test/api/edo/v1/clients/auth-global' => Http::response(
                json_encode([
                    'status' => ['code' => 0, 'message' => 'OK'],
                    'result' => 'c698a3c9-d22f-4a93-9a8d-d8310cac326a',
                ], JSON_UNESCAPED_UNICODE),
                200,
            ),
            'edoru.example.test/api/edo/v1/documents*' => Http::response(
                json_encode([
                    'status' => ['code' => 0, 'message' => 'OK'],
                    'result' => ['data' => [], 'pageInfo' => []],
                ], JSON_UNESCAPED_UNICODE),
                200,
            ),
        ]);

        $client = EdoRuClient::fromConfig(config('edo.providers.edoru'));

        $this->assertNull(Cache::get('edo:edoru:token:2PS-0078414651'));

        $client->get('/documents/find', ['direction' => 'in']);

        $token = $client->authGlobal()['token'];

        $this->assertSame('c698a3c9-d22f-4a93-9a8d-d8310cac326a', $token);
    }

    public function test_cached_token_is_reused_without_reauthentication(): void
    {
        Cache::put('edo:edoru:token:2PS-0078414651', 'cached-token', now()->addHour());

        Http::fake([
            'edoru.example.test/api/edo/v1/documents*' => Http::response(
                json_encode([
                    'status' => ['code' => 0, 'message' => 'OK'],
                    'result' => ['data' => [], 'pageInfo' => []],
                ], JSON_UNESCAPED_UNICODE),
                200,
            ),
        ]);

        $client = EdoRuClient::fromConfig(config('edo.providers.edoru'));

        $payload = $client->get('/documents/find', ['direction' => 'in']);

        $this->assertSame(['data' => [], 'pageInfo' => []], $payload['result']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Token cached-token')
            && str_contains($request->url(), '/documents/find');
        });

        // auth-global не вызывался — токен взят из кэша.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/clients/auth-global'));
    }

    public function test_401_triggers_reauthentication_and_retry(): void
    {
        Http::fake([
            'edoru.example.test/api/edo/v1/documents*' => Http::sequence()
            ->push('', 401)
                ->push(json_encode([
                'status' => ['code' => 0, 'message' => 'OK'],
                'result' => ['data' => []],
            ], JSON_UNESCAPED_UNICODE), 200),

            'edoru.example.test/api/edo/v1/clients/auth-global' => Http::response(
                json_encode([
                    'status' => ['code' => 0, 'message' => 'OK'],
                    'result' => 'fresh-token',
                ], JSON_UNESCAPED_UNICODE),
                200,
            ),
        ]);

        Cache::put('edo:edoru:token:2PS-0078414651', 'stale-token', now()->addHour());

        $client = EdoRuClient::fromConfig(config('edo.providers.edoru'));

        $payload = $client->get('/documents/find', ['direction' => 'in']);

        $this->assertSame(['data' => []], $payload['result']);
        $this->assertSame('fresh-token', Cache::get('edo:edoru:token:2PS-0078414651'));
    }

    public function test_non_zero_status_code_throws(): void
    {
        Cache::put('edo:edoru:token:2PS-0078414651', 'cached-token', now()->addHour());

        Http::fake([
            'edoru.example.test/api/edo/v1/documents*' => Http::response(
                json_encode([
                    'status' => ['code' => 401, 'message' => 'Authentication failed'],
                    'result' => null,
                ], JSON_UNESCAPED_UNICODE),
                200,
            ),
        ]);

        $client = EdoRuClient::fromConfig(config('edo.providers.edoru'));

        $this->expectException(EdoException::class);

        $client->get('/documents/find');
    }
}
