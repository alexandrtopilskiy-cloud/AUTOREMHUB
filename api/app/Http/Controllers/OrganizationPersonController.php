<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
use App\Exceptions\ApiException;
use App\Services\OrganizationPersonService;
use App\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationPersonController
{
    public function __construct(
        private readonly OrganizationPersonService $service,
    ) {}

    public function store(Request $request): JsonResponse
    {
        ApiInput::only($request, ['record']);
        $payload = $request->json('record');
        $userDto = UserDto::fromUserModel($request->user());

        if (!is_array($payload)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект record');
        }

        return response()->json([
            'record' => $this->service->create($userDto, $payload),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['patch']);
        $userDto = UserDto::fromUserModel($request->user());

        return response()->json([
            'record' => $this->service->update(
                $userDto,
                $id,
                ApiInput::patch($request),
            ),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, []);
        $userDto = UserDto::fromUserModel($request->user());

        return response()->json(
            $this->service->delete($userDto, $id),
        );
    }
}
