<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RecommendationService;
use App\Support\ApiInput;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class RecommendationController
{
    public function __construct(private readonly RecommendationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['vehicleId' => ['required', 'uuid']]);
        return response()->json(['recommendations' => $this->service->forVehicle($request->user(), $data['vehicleId'])]);
    }

    public function defer(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['revision', 'workIds', 'partIds']);
        return response()->json($this->service->defer($request->user(), $id, ApiInput::revision($request), ['workIds' => $request->json('workIds', []), 'partIds' => $request->json('partIds', [])]));
    }

    public function restoreMany(Request $request): JsonResponse
    {
        ApiInput::only($request, ['documents', 'requestId', 'requestRevision']);
        $documents = $request->json('documents');
        if (!is_array($documents) || !array_is_list($documents)) {
            throw new \App\Exceptions\ApiException(422, 'VALIDATION_ERROR', 'Передайте список documents');
        }
        foreach ($documents as $document) {
            if (!is_array($document) || !is_int($document['revision'] ?? null) || $document['revision'] < 0) {
                throw new \App\Exceptions\ApiException(422, 'VALIDATION_ERROR', 'Ревизия рекомендаций должна быть JSON-целым числом');
            }
        }
        $requestId = $request->json('requestId');
        $requestRevision = $request->json('requestRevision');
        if ($requestId !== null && (!is_string($requestId) || !is_int($requestRevision) || $requestRevision < 0)) {
            throw new \App\Exceptions\ApiException(422, 'VALIDATION_ERROR', 'Укажите заявку и её JSON-целую ревизию');
        }
        return response()->json($this->service->restoreMany($request->user(), $documents, $requestId, $requestRevision));
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['revision', 'itemIds', 'requestId', 'requestRevision']);
        if ($request->json('requestRevision') !== null && !is_int($request->json('requestRevision'))) {
            \App\Support\DomainInput::fail('requestRevision должен быть JSON-числом', 'requestRevision');
        }
        $data = $request->validate(['itemIds' => ['required', 'array'], 'requestId' => ['nullable', 'uuid'], 'requestRevision' => ['nullable', 'integer', 'min:0']]);
        return response()->json($this->service->restore($request->user(), $id, ApiInput::revision($request), $data['itemIds'], $data['requestId'] ?? null, $data['requestRevision'] ?? null));
    }
}
