<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Edo\Drivers\EdoRu\EdoRuClient;
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
     * Привязка организации к Диадоку.
     * POST /api/edo/test/connect
     */
    public function connect(Request $request): JsonResponse
    {
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
}
