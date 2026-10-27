<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Diadoc;

use App\Enums\Edo\EdoProvider;
use App\Exceptions\EdoException;
use App\Models\Organization;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DiadocClient
{
    private const IDENTITY_URL = 'https://identity.kontur.ru';
    private const TOKEN_CACHE_PREFIX = 'edo:diadoc:token:';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {}

    public function provider(): EdoProvider
    {
        return EdoProvider::Diadoc;
    }

    /**
     * Получает Access Token через Refresh Token.
     * В реальности refresh_token хранится в БД у организации.
     */
    public function getAccessToken(Organization $org): string
    {

        if (config('edo.mock')) {
            Log::channel('edo')->info('MOCK DIADOC: Имитация получения access_token');
            return 'mock-access-token-' . Str::random(10);
        }

        // Если токен есть и не истек — возвращаем
        if ($org->edo_token && $org->edo_token_expires_at && $org->edo_token_expires_at->isFuture()) {
            return $org->edo_token;
        }

        // Иначе запрашиваем новый
        $response = Http::asForm()->post(self::IDENTITY_URL . '/connect/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $org->edo_refresh_token,
        ]);

        if ($response->failed()) {
            throw EdoException::remoteError(EdoProvider::Diadoc->value, 'AUTH_FAILED', $response->body());
        }

        $accessToken = $response->json('access_token');
        $expiresIn = $response->json('expires_in', 3600);

        // Сохраняем в БД
        $org->update([
            'edo_token' => $accessToken,
            'edo_token_expires_at' => now()->addSeconds($expiresIn),
        ]);

        return $accessToken;
    }

    public function get(string $path, array $query = [], ?string $token = null): array
    {
        $uri = $this->baseUrl . $path;
        $response = $this->http($token)->get($uri, $query);

        return $this->decode($response);
    }

    public function post(string $path, array $body = [], ?string $token = null): array
    {
        $uri = $this->baseUrl . $path;
        $response = $this->http($token)->post($uri, $body);

        return $this->decode($response);
    }

    private function http(?string $token): PendingRequest
    {
        $headers = [
            'Accept' => 'application/json; charset=utf-8',
            'Content-Type' => 'application/json; charset=utf-8',
        ];

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions(['http_errors' => false]);
    }

    private function decode(Response $response): array
    {
        $body = $response->body();
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            throw EdoException::remoteError(
                provider: EdoProvider::Diadoc,
                code: 'BAD_JSON',
                message: "Невалидный JSON в ответе (HTTP {$response->status()})",
            );
        }

        if ($response->status() !== 200) {
            $message = $payload['Message'] ?? $payload['message'] ?? 'Неизвестная ошибка';
            throw EdoException::remoteError(
                provider: EdoProvider::Diadoc,
                code: "HTTP_{$response->status()}",
                message: $message,
            );
        }

        return $payload;
    }

    public function getRaw(string $path, array $query = [], ?string $token = null): string
    {
        $response = $this->http($token)->get($this->baseUrl . $path, $query);

        if ($response->status() !== 200) {
            throw EdoException::remoteError(
                provider: EdoProvider::Diadoc,
                code: "HTTP_{$response->status()}",
                message: 'Не удалось скачать файл',
            );
        }

        return $response->body();
    }

    /**
     * Имитация ответов Диадока для разных эндпоинтов.
     */
    private function mockPost(string $path, array $body): array
    {
        Log::channel('edo')->info("MOCK DIADOC: POST {$path}", ['body' => $body]);

        if (str_contains($path, '/PostMessage')) {
            return [
                'MessageId' => (string) Str::uuid(),
                'Entities' => [
                    [
                        'EntityId' => (string) Str::uuid(),
                        'DepartmentId' => null,
                    ],
                ],
            ];
        }

        // Заглушка для других эндпоинтов
        return [];
    }
}
