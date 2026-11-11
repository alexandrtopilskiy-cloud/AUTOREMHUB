<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Edo\EdoConnectRequest;
use App\Models\Organization;
use App\Services\Edo\Drivers\EdoRu\EdoRuClient;
use App\Services\Edo\EdoActService;
use App\Services\Edo\EdoDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class EdoSettingsController
{
    public function __construct(
        private readonly EdoRuClient $client,
        private readonly EdoActService $actService,
        private readonly EdoDispatcher $dispatcher,
    ) {}

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
    public function connect(EdoConnectRequest $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->user()->organization;

        $providerKey = config('edo.default');
        $credentials = $request->validated();

        // 1. Валидация в зависимости от провайдера
        try {
            // 2. Получаем провайдер через диспетчер
            $provider = $this->dispatcher->forOrganization($org);

            // 3. Вызываем единый метод connect
            $provider->connect($org, $credentials);

            return response()->json([
                'status'  => 'ok',
                'message' => "Организация успешно подключена к {$providerKey}",
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error'  => $e->getMessage(),
            ], 422);
        }
    }
}
