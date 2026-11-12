<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\DTO\EdoOrganizationDto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SbisAuthService
{
    private string $authUrl;

    public function __construct()
    {
        $config = config('edo.providers.sbis');
        $this->authUrl = $config['auth_url'];
    }

    /**
     * Возвращает X-SBISSessionID.
     * Если токен в DTO еще жив, возвращает его. Иначе запрашивает новый.
     */
    public function getSessionId(EdoOrganizationDto $dto): ?string
    {
        if (config('edo.mock', true)) {
            return 'mock-session-id';
        }

        $logger = Log::channel('edo');
        // 1. Проверяем, есть ли уже сохраненный токен и не истек ли он
        if ($dto->token && $dto->tokenExpiresAt?->isFuture()) {
            return $dto->token;
        }

        // 2. Идем в СБИС за новым токеном
        Log::channel('edo')->info("SbisAuth: Запрос нового X-SBISSessionID для организации {$dto->inn}");

        if (!$dto->login || !$dto->password) {
            throw new RuntimeException("У организации {$dto->name} не заполнены логин или пароль для СБИС.");
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'СБИС.Аутентифицировать',
            'params'  => ['Логин' => $dto->login, 'Пароль' => $dto->password],
            'id'      => uniqid('auth_', true),
        ];
        $logger = Log::channel('edo');
        $logger->info('AUTH_PAYLOAD', $payload);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json-rpc;charset=utf-8',
        ])->post($this->authUrl, $payload);

        $result = $response->json();

        if (isset($result['error'])) {
            Log::channel('edo')->error("SbisAuth: Ошибка авторизации", ['error' => $result['error']]);
            throw new RuntimeException("Ошибка авторизации СБИС: {$result['error']['message']}");
        }

        $sessionId = $result['result'] ?? null;

        if (!$sessionId) {
            throw new RuntimeException("СБИС не вернул X-SBISSessionID.");
        }

        Log::channel('edo')->info("SbisAuth: Получен новый X-SBISSessionID для {$dto->name}");

        return $sessionId;
    }
}