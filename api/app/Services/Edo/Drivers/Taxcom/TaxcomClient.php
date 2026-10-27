<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Taxcom;

use App\Exceptions\EdoException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-клиент Такском-Доклайнз Web API.
 *
 * Внимание: метод Login (логин/пароль) даёт доступ ТОЛЬКО для чтения.
 * Для обмена документами требуется CertificateLogin или CloudCertificateLogin.
 * Пока реализован только Login для проверки связи.
 */
final class TaxcomClient
{
    private const PROVIDER_LABEL = 'taxcom';
    private const TOKEN_CACHE_PREFIX = 'edo:taxcom:token:';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $login,
        private readonly ?string $password,
        private readonly ?string $integratorId,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            baseUrl: rtrim((string) ($config['base_url'] ?? ''), '/'),
            login: $config['login'] ?? null,
            password: $config['password'] ?? null,
            integratorId: $config['integrator_id'] ?? null,
            timeout: (int) ($config['http']['timeout'] ?? 30),
            connectTimeout: (int) ($config['http']['connect_timeout'] ?? 10),
        );
    }

    /**
     * GET-запрос к API Такском.
     */
    public function get(string $path, array $query = []): array
    {
        $token = $this->resolveToken();
        $response = $this->rawRequest('GET', $path, $query, [], $token);

        if ($response->status() === 401) {
            $this->forgetToken();
            $token = $this->resolveToken();
            $response = $this->rawRequest('GET', $path, $query, [], $token);
        }

        return $this->decode($response);
    }

    /**
     * POST-запрос к API Такском.
     */
    public function post(string $path, array $body = []): array
    {
        $token = $this->resolveToken();
        $response = $this->rawRequest('POST', $path, [], $body, $token);

        if ($response->status() === 401) {
            $this->forgetToken();
            $token = $this->resolveToken();
            $response = $this->rawRequest('POST', $path, [], $body, $token);
        }

        return $this->decode($response);
    }

    /**
     * Авторизация по логину и паролю.
     * Возвращает Assistant-Key (маркер временного доступа).
     */
    private function login(): string
    {
        if ($this->login === null || $this->password === null || $this->login === '' || $this->password === '') {
            throw EdoException::notConfigured();
        }

        $response = Http::withHeaders([
            'Integrator-Id' => $this->integratorId ?? '',
        ])
        ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->get($this->baseUrl . '/API/Login', [
            'login' => $this->login,
            'password' => $this->password,
        ]);

        if ($response->status() !== 200) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'AUTH_FAILED',
                'Не удалось авторизоваться в Такском: ' . $response->body(),
            );
        }

        // В ответе приходит XML с Assistant-Key.
        // Парсим его.
        $body = $response->body();
        if (preg_match('/<Assistant-Key>(.*?)<\/Assistant-Key>/s', $body, $matches)) {
            return $matches[1];
        }

        throw EdoException::remoteError(
            self::PROVIDER_LABEL,
            'NO_TOKEN',
            'В ответе Такском нет Assistant-Key',
        );
    }

    private function resolveToken(): string
    {
        $cacheKey = self::TOKEN_CACHE_PREFIX . ($this->login ?? 'default');

        /** @var string|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->login();
        Cache::put($cacheKey, $token, now()->addHours(23));

        return $token;
    }

    private function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_PREFIX . ($this->login ?? 'default'));
    }

    private function rawRequest(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        ?string $token = null,
    ): Response {
        $request = $this->http();

        if ($token !== null) {
            $request = $request->withHeaders(['Assistant-Key' => $token]);
        }

        $url = $this->baseUrl . $path;

        return $method === 'GET'
            ? $request->get($url, $query)
            : $request->post($url, $body);
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Accept' => 'application/xml, application/json',
            'Content-Type' => 'application/json; charset=utf-8',
        ])
        ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions(['http_errors' => false]);
    }

    private function decode(Response $response): array
    {
        $body = $response->body();

        if ($response->status() !== 200) {
            Log::warning('Taxcom: ошибка HTTP', ['status' => $response->status(), 'body' => $body]);
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'HTTP_' . $response->status(),
                'Ошибка API Такском',
            );
        }

        // Такском может возвращать XML или JSON. Пробуем JSON, потом XML.
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }

        // Если XML — пока возвращаем как есть (строкой), позже добавим парсер.
        return ['_raw' => $body];
    }
}
