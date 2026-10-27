<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Edo\Drivers\EdoRu\EdoRuClient;
use Illuminate\Http\JsonResponse;
use App\Services\Edo\EdoActService;

final class EdoTestController
{
    public function __construct(
        private readonly EdoRuClient $client,
        private readonly EdoActService $actService,
    ) {
    }

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

    public function docTypes(): JsonResponse
    {
        $type = $this->actService->getActDocTypeId($this->client);

        return response()->json($type);
    }
}
