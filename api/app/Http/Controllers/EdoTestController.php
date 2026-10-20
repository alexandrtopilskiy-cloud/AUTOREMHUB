<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Edo\Providers\EdoRu\EdoRuClient;
use Illuminate\Http\JsonResponse;

final class EdoTestController
{
    public function __construct(
        private readonly EdoRuClient $client,
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
}
