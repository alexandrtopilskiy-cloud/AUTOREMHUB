<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\OrganizationPersonService;
use App\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationPersonController
{
    public function __construct(
        private readonly OrganizationPersonService $service,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        ApiInput::only($request, ['record']);
        $payload = $request->json('record');

        if (!is_array($payload)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект record');
        }

        return response()->json([
            'record' => $this->service->create($request->user(), $payload),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['patch']);

        return response()->json([
            'record' => $this->service->update(
                $request->user(),
                $id,
                ApiInput::patch($request),
            ),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, []);

        return response()->json(
            $this->service->delete($request->user(), $id),
        );
    }
}
