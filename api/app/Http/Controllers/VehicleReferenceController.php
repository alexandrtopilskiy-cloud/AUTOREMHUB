<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
use App\Http\Requests\CatalogVehicleReferenceRequest;
use App\Http\Requests\DrivesVehicleReferenceRequest;
use App\Http\Requests\EnginesVehicleReferenceRequest;
use App\Http\Requests\MakesVehicleReferenceRequest;
use App\Http\Requests\ModelsVehicleReferenceRequest;
use App\Http\Resources\VehicleSelectionOptionsResource;
use App\Http\Requests\SelectionVehicleReferenceRequest;
use App\Http\Requests\StoreCatalogVehicleReferenceRequest;
use App\Http\Requests\StoreVehicleReferenceRequest;
use App\Http\Requests\TransmissionsVehicleReferenceRequest;
use App\Http\Resources\VehicleCatalogModelResource;
use App\Http\Resources\VehicleCatalogResource;
use App\Http\Resources\VehicleDrivesResource;
use App\Http\Resources\VehicleEnginesResource;
use App\Http\Resources\VehicleGenerationsResource;
use App\Http\Resources\VehicleMakesResource;
use App\Http\Resources\VehicleModelsResource;
use App\Http\Resources\VehicleTransmissionsResource;
use App\Services\VehicleReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

final class VehicleReferenceController
{
    public function __construct(
        private readonly VehicleReferenceService $service,
    ) {
    }

    public function selection(SelectionVehicleReferenceRequest $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $data = $request->validated();

        // Вызываем сервис и оборачиваем ответ в Resource
        return new VehicleSelectionOptionsResource(
            $this->service->selectionOptions(
                $userDto,
                $data['makeId'],
                $data['step'],
                $data['filters'] ?? [],
            )
        );
    }

    public function makes(MakesVehicleReferenceRequest $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $validated = $request->validated();

        return new VehicleMakesResource(
            $this->service->makes(
                $userDto,
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 50),
            )
        );
    }

    public function models(ModelsVehicleReferenceRequest $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $validated = $request->validated();

        return new VehicleModelsResource(
            $this->service->models(
                $userDto,
                (string) $validated['makeId'],
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 100),
                (bool) ($validated['uniqueNames'] ?? false),
            )
        );
    }

    public function generations(Request $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $validated = $request->validated();

        return new VehicleGenerationsResource(
            $this->service->generations(
                $userDto,
                (string) $validated['modelId'],
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 100),
            )
        );
    }

    public function engines(EnginesVehicleReferenceRequest $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $validated = $request->validated();
        $generationId = isset($validated['generationId']) && $validated['generationId'] !== null
            ? (string) $validated['generationId']
            : null;

        return new VehicleEnginesResource(
            $this->service->engines(
                $userDto,
                (string) $validated['modelId'],
                $generationId,
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 100),
            )
        );
    }

    public function transmissions(TransmissionsVehicleReferenceRequest $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $validated = $request->validated();

        return new VehicleTransmissionsResource(
            $this->service->transmissions(
                $userDto,
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 50),
            )
        );
    }

    public function drives(DrivesVehicleReferenceRequest $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $validated = $request->validated();

        return new VehicleDrivesResource(
            $this->service->drives(
                $userDto,
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 50),
            )
        );
    }

    public function catalog(CatalogVehicleReferenceRequest $request): JsonResource
    {
        $userDto = UserDto::fromUserModel($request->user());
        $validated = $request->validated();

        $filters = array_intersect_key($validated, array_flip([
            'make',
            'model',
            'modelYearFrom',
            'modelYearTo',
            'engineIndex',
            'bodyCode',
            'horsepower',
            'engineVolumeLiters',
            'transmission',
            'driveType',
            'engineType',
        ]));

        return new VehicleCatalogResource(
            $this->service->catalog(
                $userDto,
                $filters,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['perPage'] ?? 100),
            )
        );
    }

    public function storeCatalog(StoreCatalogVehicleReferenceRequest $request): JsonResponse
    {
        $userDto = UserDto::fromUserModel($request->user());
        $record = $request->validated('record');

        $model = $this->service->create($userDto, $record, true);

        // Возвращаем JsonResource, Laravel автоматически поставит статус 201 (Created)
        // если использовать ->response()->setStatusCode(201), либо можно вернуть
        // просто resource. Но для явности статуса 201:
        return (new VehicleCatalogModelResource($model))
            ->response()
            ->setStatusCode(201);
    }

    public function store(StoreVehicleReferenceRequest $request): JsonResponse
    {
        $userDto = UserDto::fromUserModel($request->user());
        $record = $request->validated('record');

        $model = $this->service->create($userDto, $record);

        return (new VehicleCatalogModelResource($model))
            ->response()
            ->setStatusCode(201);
    }
}
