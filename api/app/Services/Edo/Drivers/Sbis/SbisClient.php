<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Enums\Edo\EdoProvider;
use Illuminate\Support\Facades\Crypt;
use App\Models\Organization;

final class SbisClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authUrl,
        private readonly Organization $organization,
        private readonly ?string $login,
        private readonly ?string $password,
        private readonly ?string $sessionId,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {}

    /**
     * Выполняет JSON-RPC запрос к СБИС.
     */
    public function request(string $method, array $params = []): array
    {
        $logger = Log::channel('edo');
        $logger->info("START $method");
        $sessionId = $this->resolveSessionId();
        $logger->info($sessionId);

        $response = $this->rawRequest($method, $params);

        // Если сессия истекла (401), пробуем обновить и повторить
        if ($response->status() === 401) {
            $this->forgetSession();
            $sessionId = $this->resolveSessionId(true);
            $response = $this->rawRequest($method, $params);
        }

        return $this->decode($response);
    }

    /**
     * Получение или обновление сессии.
     */
    private function resolveSessionId(bool $forceRefresh = false): ?string
    {
        $logger = Log::channel('edo');
        // Если не форсируем обновление и сессия есть — используем её
        if (!$forceRefresh && !empty($this->organization->edo_token)) {
            // Токен в БД может быть зашифрован, поэтому расшифровываем
            return $this->organization->edo_token;
        }

        // В мок-режиме просто возвращаем заглушку
        if (config('edo.mock')) {
            return 'mock-session-id';
        }

        // Если нужно обновить, но нет логина/пароля — падаем
        if (empty($this->login) || empty($this->password)) {
            throw new RuntimeException('Сессия СБИС истекла или отсутствует. Нет логина/пароля для переавторизации.');
        }

        // Делаем запрос на авторизацию
        return $this->login();
    }

    /**
     * Авторизация по логину и паролю.
     * В СБИС метод: СБИС.Аутентифицировать
     */
    private function login(): ?string
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'СБИС.Аутентифицировать',
            'params'  => [
                'Логин'  => $this->login,
                'Пароль' => $this->password,
            ],
            'id' => Str::uuid()->toString(),
        ];

        $response = Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->post($this->authUrl, $payload);

        if ($response->failed()) {
            throw new RuntimeException('Ошибка авторизации в СБИС: ' . $response->body());
        }

        $result = $response->json();

        if (isset($result['error'])) {
            throw new RuntimeException("Ошибка СБИС (Auth): {$result['error']['message']}");
        }

        $sessionId = $result['result'];

        $expiresIn = 3600 * 3; // Сессия СБИС обычно живет 3 часа (уточнить в доке)

        DB::table('organizations')
            ->where('id', $this->organization->id)
            ->update([
                'edo_provider'        => EdoProvider::Sbis->value,
                'edo_token'           => Crypt::encryptString($sessionId),
                'edo_token_expires_at'=> now()->addSeconds(max(0, $expiresIn - 60)),
                'edo_registered_at'   => now(),
                'updated_at'          => now(),
            ]);

        // 3. Обновляем модель в памяти, чтобы она не считала себя "грязной"
        $this->organization->edo_provider = EdoProvider::Sbis->value;
        $this->organization->edo_token = Crypt::encryptString($sessionId);
        $this->organization->edo_token_expires_at = now()->addSeconds(max(0, $expiresIn - 60));
        $this->organization->syncOriginal();

        return $sessionId;
    }

    private function forgetSession(): void
    {
        if (config('edo.mock')) {
            return;
        }

        // 1. Очищаем токен в БД
        DB::table('organizations')
            ->where('id', $this->organization->id)
            ->update([
                'edo_token'            => null,
                'edo_token_expires_at' => null,
                'updated_at'           => now(),
            ]);

        // 2. Очищаем модель в памяти
        $this->organization->edo_token = null;
        $this->organization->edo_token_expires_at = null;
        $this->organization->syncOriginal();

        Log::channel('edo')->info("SbisClient: Сессия для организации {$this->organization->id} сброшена.");
    }

    /**
     * Базовый запрос без логики обновления токена.
     */
    private function rawRequest(string $method, array $params): Response
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => Str::uuid()->toString(),
        ];

        return Http::withHeaders([
            'X-SBISSessionID' => $this->sessionId,
            'Content-Type'    => 'application/json-rpc;charset=utf-8',
            'Accept'          => 'application/json-rpc',
            'User-Agent'      => 'AUTOREMHUB/1.0',
        ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->post($this->baseUrl, $payload);
    }

    private function decode(Response $response): array
    {
        if ($response->failed() && $response->status() !== 401) {
            Log::channel('edo')->error("СБИС API HTTP Error", ['response' => $response->body()]);
            throw new RuntimeException("Ошибка HTTP при запросе к СБИС: {$response->status()}");
        }

        $result = $response->json();

        if (isset($result['error'])) {
            Log::channel('edo')->error("СБИС API RPC Error", ['error' => json_encode($result['error'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)]);
            throw new RuntimeException("Ошибка СБИС: [{$result['error']['code']}] {$result['error']['message']}");
        }

        return $result['result'] ?? [];
    }
}