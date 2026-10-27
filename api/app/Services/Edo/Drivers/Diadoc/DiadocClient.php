<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Diadoc;

use App\Exceptions\EdoException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class DiadocClient
{
    private const PROVIDER_LABEL = 'diadoc';
    private const TOKEN_CACHE_PREFIX = 'edo:diadoc:token:';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $accessToken, // access_token из Кабинета интегратора
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        $response = $this->http()->get($this->baseUrl . $path, $query);
        return $this->decode($response);
    }

    public function post(string $path, array $body = []): array
    {
        $response = $this->http()->post($this->baseUrl . $path, $body);
        return $this->decode($response);
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept' => 'application/json; charset=utf-8',
            'Content-Type' => 'application/json; charset=utf-8',
        ])
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
                self::PROVIDER_LABEL,
                'BAD_JSON',
                'Невалидный JSON в ответе (HTTP ' . $response->status() . ')',
            );
        }

        if ($response->status() !== 200) {
            $message = $payload['Message'] ?? $payload['message'] ?? 'Неизвестная ошибка';
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'HTTP_' . $response->status(),
                $message,
            );
        }

        return $payload;
    }

    public function getRaw(string $path, array $query = []): string
    {
        $response = $this->http()->get($this->baseUrl . $path, $query);

        if ($response->status() !== 200) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'HTTP_' . $response->status(),
                'Не удалось скачать файл',
            );
        }

        return $response->body();
    }
}
