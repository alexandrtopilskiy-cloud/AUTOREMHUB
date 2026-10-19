<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\DocumentLockService;
use App\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DocumentLockController
{
    public function __construct(
        private readonly DocumentLockService $service,
    ) {
    }

    public function acquire(Request $request): JsonResponse
    {
        ApiInput::only($request, ['resourceType', 'resourceKey', 'label']);
        $payload = $request->json()->all();

        if (!is_array($payload)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте параметры блокировки');
        }

        return response()->json([
            'lock' => $this->service->acquire($request->user(), $payload),
        ], 201);
    }

    public function heartbeat(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, []);

        return response()->json([
            'lock' => $this->service->heartbeat($request->user(), $id),
        ]);
    }

    public function release(Request $request, string $id): Response
    {
        ApiInput::only($request, []);
        $this->service->release($request->user(), $id);

        return response()->noContent();
    }
}
