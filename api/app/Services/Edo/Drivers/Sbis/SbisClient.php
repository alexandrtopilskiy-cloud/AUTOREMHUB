<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Enums\Edo\EdoProvider;
use App\Models\Organization;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * JSON-RPC клиент СБИС (API ЭДО).
 *
 * Логин — organizations.edo_client_id, пароль — organizations.edo_refresh_token,
 * идентификатор сессии (X-SBISSessionID) — organizations.edo_token
 * (оба поля зашифрованы cast'ом модели).
 */
final class SbisClient
{
    private const SESSION_TTL_SECONDS = 3 * 3600;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authUrl,
        private readonly Organization $organization,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {}

    /**
     * Выполняет JSON-RPC запрос к СБИС. При истёкшей сессии один раз
     * переавторизуется и повторяет запрос.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array
    {
        Log::channel('edo')->info("SBIS: {$method}", ['organization_id' => $this->organization->id]);

        $response = $this->rawRequest($method, $params, $this->sessionId());

        if ($this->isSessionError($response)) {
            $this->forgetSession();
            $response = $this->rawRequest($method, $params, $this->sessionId());
        }

        return $this->decode($response);
    }

    /**
     * Авторизация по логину и паролю (СБИС.Аутентифицировать).
     * Сохраняет идентификатор сессии в организации.
     */
    public function login(): string
    {
        $login = (string) ($this->organization->edo_client_id ?? '');
        $password = (string) ($this->organization->edo_refresh_token ?? '');

        if ($login === '' || $password === '') {
            throw new RuntimeException("У организации «{$this->organization->name}» не заполнены логин или пароль СБИС (edo:sbis-connect).");
        }

        $response = Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders(['Content-Type' => 'application/json-rpc;charset=utf-8'])
            ->post($this->authUrl, [
                'jsonrpc' => '2.0',
                'method'  => 'СБИС.Аутентифицировать',
                'params'  => ['Логин' => $login, 'Пароль' => $password],
                'id'      => Str::uuid()->toString(),
            ]);

        $result = $response->json();

        if ($response->failed() || isset($result['error']) || !is_string($result['result'] ?? null)) {
            $message = is_array($result) ? (string) ($result['error']['message'] ?? $response->body()) : $response->body();
            Log::channel('edo')->error('SBIS: ошибка авторизации', ['organization_id' => $this->organization->id, 'message' => $message]);

            throw new RuntimeException('Ошибка авторизации в СБИС: ' . $message);
        }

        $sessionId = $result['result'];

        $this->organization->forceFill([
            'edo_provider'         => EdoProvider::Sbis,
            'edo_token'            => $sessionId,
            'edo_token_expires_at' => now()->addSeconds(self::SESSION_TTL_SECONDS - 60),
            'edo_registered_at'    => $this->organization->edo_registered_at ?? now(),
        ])->save();

        return $sessionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function readDocument(string $externalId): array
    {
        return $this->request('СБИС.ПрочитатьДокумент', [
            'Документ' => ['Идентификатор' => $externalId],
        ]);
    }

    /**
     * Скачивает файл по ссылке СБИС (Файл.Ссылка) с сессией организации.
     * Возвращает содержимое в base64.
     */
    public function downloadBase64(string $url): string
    {
        $get = fn (string $sessionId): Response => Http::withHeaders([
            'X-SBISSessionID' => $sessionId,
            'User-Agent'      => 'AUTOREMHUB/1.0',
        ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->get($url);

        $response = $get($this->sessionId());
        if ($response->status() === 401 || $response->status() === 403) {
            $this->forgetSession();
            $response = $get($this->sessionId());
        }

        if ($response->failed()) {
            Log::channel('edo')->error('SBIS: ошибка скачивания файла', ['status' => $response->status()]);

            throw new RuntimeException("Не удалось скачать файл из СБИС: HTTP {$response->status()}");
        }

        return base64_encode($response->body());
    }

    private function sessionId(): string
    {
        $token = (string) ($this->organization->edo_token ?? '');
        $expires = $this->organization->edo_token_expires_at;

        if ($token !== '' && ($expires === null || $expires->isFuture())) {
            return $token;
        }

        return $this->login();
    }

    private function forgetSession(): void
    {
        $this->organization->forceFill([
            'edo_token'            => null,
            'edo_token_expires_at' => null,
        ])->save();

        Log::channel('edo')->info("SBIS: сессия организации {$this->organization->id} сброшена");
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rawRequest(string $method, array $params, string $sessionId): Response
    {
        return Http::withHeaders([
            'X-SBISSessionID' => $sessionId,
            'Content-Type'    => 'application/json-rpc;charset=utf-8',
            'Accept'          => 'application/json-rpc',
            'User-Agent'      => 'AUTOREMHUB/1.0',
        ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->post($this->baseUrl, [
                'jsonrpc' => '2.0',
                'method'  => $method,
                'params'  => $params,
                'id'      => Str::uuid()->toString(),
            ]);
    }

    private function isSessionError(Response $response): bool
    {
        if ($response->status() === 401) {
            return true;
        }

        $message = mb_strtolower((string) ($response->json('error.message') ?? ''));

        return $message !== '' && (str_contains($message, 'сесси') || str_contains($message, 'авториз') || str_contains($message, 'unauthorized'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $result = $response->json();

        if (is_array($result) && isset($result['error'])) {
            Log::channel('edo')->error('SBIS: ошибка RPC', ['error' => $result['error']]);
            $error = $result['error'];
            $details = is_array($error) ? trim((string) ($error['details'] ?? '')) : '';

            throw new RuntimeException('Ошибка СБИС: ' . (is_array($error) ? (string) ($error['message'] ?? '') : (string) $error) . ($details !== '' ? ' (' . $details . ')' : ''));
        }

        if ($response->failed()) {
            Log::channel('edo')->error('SBIS: ошибка HTTP', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 2000)]);

            throw new RuntimeException("Ошибка HTTP при запросе к СБИС: {$response->status()}");
        }

        $value = is_array($result) ? ($result['result'] ?? []) : [];

        return is_array($value) ? $value : ['value' => $value];
    }
}
