<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\VehicleCatalogService;
use App\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VehicleCatalogController
{
    public function __construct(
        private readonly VehicleCatalogService $service,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        ApiInput::only($request, ['record']);
        $payload = $request->json('record');

        if (!is_array($payload)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект record');
        }

        $result = $this->service->create($request->user(), $payload);

        return response()->json($result, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['patch']);

        return response()->json(
            $this->service->update(
                $request->user(),
                $id,
                ApiInput::patch($request),
            ),
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, []);

        return response()->json(
            $this->service->delete($request->user(), $id),
        );
    }

    public function review(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['decision', 'comment']);
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment'  => ['present', 'string', 'max:10000'],
        ]);

        return response()->json([
            'record' => $this->service->review(
                $request->user(),
                $id,
                $validated['decision'],
                $validated['comment'],
            ),
        ]);
    }
}
