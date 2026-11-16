<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Edo\EdoProvider;
use App\Models\Organization;
use App\Services\Edo\Drivers\EdoRu\EdoRuClient;
use App\Services\Edo\Drivers\Sbis\SbisClientFactory;
use App\Services\Edo\EdoActService;
use App\Services\Edo\EdoDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EdoSettingsController
{
    public function __construct(
        private readonly EdoRuClient $client,
        private readonly EdoActService $actService,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function auth(): JsonResponse
    {
        // Проверяем, что клиент видит конфиг
        $authResult = $this->client->authGlobal();

        return response()->json([
            'status' => 'ok',
            'token' => $authResult['token'] ?? null,
            'edoOrgId' => $authResult['edoOrgId'] ?? null,
        ]);
    }

    public function documents(): JsonResponse
    {
        // Запрашиваем список входящих документов
        $result = $this->client->get('/documents/find', [
            'direction' => 'in',
            'pageIndex' => 1,
            'pageRecords' => 10,
            'typeOfDate' => 'RECEIVED_DATE',
        ]);

        return response()->json($result);
    }

    /**
     * Текущее подключение организации пользователя к ЭДО (для окна «Настройки ЭДО»).
     * GET /api/edo/settings
     */
    public function settings(Request $request): JsonResponse
    {
        /** @var Organization|null $org */
        $org = $request->user()->organization;
        $provider = (string) config('edo.default', EdoProvider::None->value);

        return response()->json(['settings' => [
            'provider'      => $provider,
            'providerLabel' => EdoProvider::tryFrom($provider)?->label() ?? $provider,
            'mock'          => (bool) config('edo.mock', true),
            'organization'  => $org?->name,
            'connected'     => $org !== null && $org->edo_provider?->value === $provider && (string) $org->edo_client_id !== '',
            'login'         => $org?->edo_client_id,
            'ediCode'       => $org?->edo_edi_code,
            'connectedAt'   => $org?->edo_registered_at?->toISOString(),
        ]]);
    }

    /**
     * Привязка организации к оператору ЭДО (EDO_PROVIDER): СБИС — логин,
     * пароль и идентификатор участника ЭДО; Диадок — BoxId и refresh token.
     * POST /api/edo/connect
     */
    public function connect(Request $request): JsonResponse
    {
        if (config('edo.default') === EdoProvider::Sbis->value) {
            return $this->connectSbis($request);
        }

        $validated = $request->validate([
            'refresh_token' => 'required|string',
            'client_id'     => 'required|string', // BoxId
        ]);

        $org = $request->user()->organization;

        if (!$org) {
            return response()->json(['error' => 'У пользователя нет организации'], 400);
        }

        try {
            // Принудительно ставим провайдера (если еще не стоит)
            $org->update(['edo_provider' => 'diadoc']);

            // Вызываем метод из Диспетчера
            $this->dispatcher->connectDiadocOrganization(
                $org,
                $validated['refresh_token'],
                $validated['client_id']
            );


            return response()->json([
                'status' => 'ok',
                'message' => 'Организация успешно подключена к Диадоку',
                'box_id' => $org->fresh()->edo_client_id,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    private function connectSbis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'edi_code' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var Organization|null $org */
        $org = $request->user()->organization;
        if ($org === null) {
            return response()->json(['error' => 'У пользователя нет организации'], 400);
        }

        $password = (string) ($validated['password'] ?? '');
        if ($password === '' && ((string) $org->edo_client_id !== $validated['login'] || (string) $org->edo_refresh_token === '')) {
            return response()->json(['error' => 'Укажите пароль СБИС'], 422);
        }

        $previous = $org->only(['edo_provider', 'edo_client_id', 'edo_refresh_token', 'edo_edi_code', 'edo_token', 'edo_token_expires_at']);
        $org->forceFill([
            'edo_provider'         => EdoProvider::Sbis,
            'edo_client_id'        => trim($validated['login']),
            'edo_refresh_token'    => $password !== '' ? $password : $org->edo_refresh_token,
            'edo_edi_code'         => trim((string) ($validated['edi_code'] ?? '')) ?: $org->edo_edi_code,
            'edo_token'            => null,
            'edo_token_expires_at' => null,
        ]);

        try {
            if (config('edo.mock', true)) {
                $org->forceFill(['edo_registered_at' => now()])->save();
            } else {
                // Проверка логина и пароля: СБИС.Аутентифицировать (сохраняет сессию).
                app(SbisClientFactory::class)->make($org)->login();
            }
        } catch (\Throwable $e) {
            $org->forceFill($previous)->save();

            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'  => 'ok',
            'message' => 'Организация «' . $org->name . '» подключена к СБИС',
        ]);
    }
}
