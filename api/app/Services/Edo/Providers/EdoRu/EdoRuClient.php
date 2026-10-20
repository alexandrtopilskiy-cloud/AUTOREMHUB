<?php

declare(strict_types=1);

namespace App\Services\Edo\Providers\EdoRu;

use App\Exceptions\EdoException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

/**
 * HTTP-клиент ИС «ЭДО.Поток» (ЭДО.ру). API v5.2.
 *
 * Особенности:
 *  - кодировка запросов/ответов — Windows-1251 (конвертируем на границе);
 *  - авторизация: POST /clients/auth-global → токен, живёт 24 часа;
 *  - все запросы, кроме auth, требуют заголовок Authorization: Token {token};
 *  - при 401 — сброс кэша токена и одна повторная попытка;
 *  - ответы обёрнуты: { status: {code, message}, result: mixed };
 *    успех — HTTP 200 И status.code === 0;
 *  - download-doc / show-doc-pdf возвращают бинарный поток (не JSON).
 */
final class EdoRuClient
{
    private const TOKEN_CACHE_TTL_HOURS = 23;
    private const TOKEN_CACHE_PREFIX    = 'edo:edoru:token:';
    private const PROVIDER_LABEL        = 'edoru';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authMode,
        private readonly ?string $clientId,
        private readonly ?string $password,
        private readonly ?string $fingerprint,
        private readonly string $encoding = 'windows-1251',
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            baseUrl:        rtrim((string) ($config['base_url'] ?? ''), '/'),
            authMode:       (string) ($config['auth_mode'] ?? 'auth-global'),
            clientId:       $config['client_id'] ?? null,
            password:       $config['password'] ?? null,
            fingerprint:    $config['fingerprint'] ?? null,
            encoding:       (string) ($config['encoding'] ?? 'windows-1251'),
            timeout:        (int) ($config['http']['timeout'] ?? 30),
            connectTimeout: (int) ($config['http']['connect_timeout'] ?? 10),
        );
    }

    /* ────────────────────────────────────────────────────────────
     | Публичные методы
     ──────────────────────────────────────────────────────────── */

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, query: $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, body: $body);
    }

    /**
     * GET с бинарным ответом (download-doc, show-doc-pdf).
     * Возвращает тело ответа как есть (бинарные байты).
     *
     * @param  array<string, scalar|null>  $query
     */
    public function getRaw(string $path, array $query = []): string
    {
        $token    = $this->resolveToken();
        $response = $this->rawRequest('GET', $path, $query, [], $token);

        if ($response->status() === 401) {
            $this->forgetToken();
            $token    = $this->resolveToken();
            $response = $this->rawRequest('GET', $path, $query, [], $token);
        }

        if ($response->status() !== 200) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                "HTTP_{$response->status()}",
                'не удалось скачать файл',
            );
        }

        return $response->body();
    }

    /**
     * Низкоуровневая авторизация. Не кэширует — это делает resolveToken().
     *
     * @return array{token: string, edoOrgId: ?string}
     */
    public function authGlobal(): array
    {
        if ($this->authMode !== 'auth-global') {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'AUTH_MODE',
                "auth-global недоступен при auth_mode={$this->authMode}",
            );
        }

        if ($this->isEmptyCredentails()) {
            throw EdoException::notConfigured();
        }

        $response = $this->rawRequest('POST', '/clients/auth-global', body: [
            'clientId' => $this->clientId,
            'password' => $this->password,
        ]);

        $payload = $this->decode($response);

        $token = Arr::get($payload, 'result.0.token');
        $edoOrgId = Arr::get($payload, 'result.0.organization.edoId');

        return ['token' => $token, 'edoOrgId' => $edoOrgId];
    }

    private function isEmptyCredentails(): bool
    {
        return $this->clientId === null || $this->clientId === '' || $this->password === null || $this->password === '';
    }

    /* ────────────────────────────────────────────────────────────
     | Внутренняя кухня
     ──────────────────────────────────────────────────────────── */

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>        $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $token    = $this->resolveToken();
        $response = $this->rawRequest($method, $path, $query, $body, $token);

        if ($response->status() === 401) {
            $this->forgetToken();
            $token    = $this->resolveToken();
            $response = $this->rawRequest($method, $path, $query, $body, $token);
        }

        return $this->decode($response);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>        $body
     */
    private function rawRequest(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        ?string $token = null,
    ): Response {
        $request = $this->http();

        if ($token !== null) {
            $request = $request->withHeaders(['Authorization' => 'Token ' . $token]);
        }

        $url = $this->baseUrl . $path;

        return $method === 'GET'
            ? $request->get($url, $query)
            : $request->post($url, $body);
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json; charset=' . $this->encoding,
        ])
        ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions(['http_errors' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $rawBody  = $response->body();

        $utf8Body = mb_check_encoding($rawBody, 'UTF-8')
            ? $rawBody
            : mb_convert_encoding($rawBody, 'UTF-8', $this->encoding);

        $payload = json_decode($utf8Body, true);

        if (!is_array($payload)) {
            Log::warning('EdoRu: невалидный JSON в ответе', [
                'status' => $response->status(),
                'body'   => mb_substr($utf8Body, 0, 500),
            ]);

            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'BAD_JSON',
                'невалидный JSON в ответе (HTTP ' . $response->status() . ')',
            );
        }

        $code    = $payload['status']['code']    ?? null;
        $message = (string) ($payload['status']['message'] ?? '');

        if ($response->status() !== 200) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                "HTTP_{$response->status()}" . ($code !== null ? "/code={$code}" : ''),
                $message,
            );
        }

        if ((int) $code !== 0) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                "code={$code}",
                $message,
            );
        }

        return $payload;
    }

    /* ────────────────────────────────────────────────────────────
     | Токен
     ──────────────────────────────────────────────────────────── */

    private function resolveToken(): string
    {
        $cacheKey = $this->tokenCacheKey();

        /** @var string|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = match ($this->authMode) {
            'auth-global'  => $this->authGlobal()['token'],
            'auth-with-ds' => throw EdoException::operationNotSupported(
                'auth-with-ds',
                self::PROVIDER_LABEL,
            ),
            default => throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'AUTH_MODE',
                "неизвестный auth_mode={$this->authMode}",
            ),
        };

        Cache::put($cacheKey, $token, now()->addHours(self::TOKEN_CACHE_TTL_HOURS));

        return $token;
    }

    private function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    private function tokenCacheKey(): string
    {
        $identity = $this->clientId ?? $this->fingerprint ?? 'default';

        return self::TOKEN_CACHE_PREFIX . $identity;
    }
}
