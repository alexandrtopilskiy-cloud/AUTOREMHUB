<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
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
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);

        if (!is_array($payload)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект record');
        }

        $result = $this->service->create($userDto, $payload);

        return response()->json($result, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['patch']);
        $userDto = UserDto::fromUserModel($request->user());

        return response()->json(
            $this->service->update(
                $userDto,
                $id,
                ApiInput::patch($request),
            ),
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, []);
        $userDto = UserDto::fromUserModel($request->user());

        return response()->json(
            $this->service->delete($userDto, $id),
        );
    }

    public function review(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['decision', 'comment']);
        $userDto = UserDto::fromUserModel($request->user());

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment'  => ['present', 'string', 'max:10000'],
        ]);

        return response()->json([
            'record' => $this->service->review(
                $userDto,
                $id,
                $validated['decision'],
                $validated['comment'],
            ),
        ]);
    }
}
