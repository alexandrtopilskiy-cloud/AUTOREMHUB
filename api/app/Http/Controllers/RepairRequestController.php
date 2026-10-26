<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\AcceptVehicleDto;
use App\DTO\CustomerSlotDecisionDto;
use App\DTO\StationDecisionDto;
use App\DTO\TransitionDto;
use App\DTO\UpdateRepairRequestDto;
use App\Enums\TransitionAction;
use App\Http\Requests\AcceptVehicleRequest;
use App\Http\Requests\CustomerSlotDecisionRequest;
use App\Http\Requests\DestroyDraftsRequest;
use App\Http\Requests\StationDecisionRequest;
use App\Http\Requests\TransitionRequest;
use App\Http\Requests\UpdateRepairRequestRequest;
use App\Http\Resources\RepairRequestCollection;
use App\Http\Resources\RepairRequestHistoryResource;
use App\Http\Resources\RepairRequestResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\RepairRequest;
use App\Services\RepairRequestService;
use App\Support\ApiPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RepairRequestController
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RepairRequestService $service,
        private readonly ApiPresenter $apiPresenter,
    ) {}

    public function index(Request $request): RepairRequestCollection
    {
        $this->authorize('viewAny', RepairRequest::class);

        $user = $request->user();
        $requests = RepairRequest::forUser($user)->latest('id')->get();
        $this->apiPresenter->preloadRecommendations($requests, $user);

        return new RepairRequestCollection($requests);
    }

    public function show(Request $request, string $id): RepairRequestResource
    {
        $document = RepairRequest::resolveFor($request->user(), $id);
        $this->authorize('view', $document);

        return new RepairRequestResource($document);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', RepairRequest::class);
        $document = $this->service->create($request->user());

        return (new RepairRequestResource($document))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRepairRequestRequest $request, string $id): RepairRequestResource
    {
        $document = RepairRequest::resolveFor(user: $request->user(), key: $id);
        $this->authorize('update', $document);

        $dto = UpdateRepairRequestDto::fromRequest($request, $id);
        $document = $this->service->update(user: $request->user(), dto: $dto);

        return new RepairRequestResource($document);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $document = RepairRequest::resolveFor($request->user(), $id);
        $this->authorize('delete', $document);

        $deleted = $this->service->deleteDrafts(user: $request->user(), ids:[$document->uuid]);

        return response()->json(['deletedIds' => $deleted]);
    }

    public function destroyDrafts(DestroyDraftsRequest $request): JsonResponse
    {
        return response()->json([
            'deletedIds' => $this->service->deleteDrafts(
                $request->user(),
                $request->validated('ids')
            ),
        ]);
    }

    public function next(TransitionRequest $request, string $id): RepairRequestResource
    {
        return $this->transition($request, $id, TransitionAction::Next);
    }

    public function returnBack(TransitionRequest $request, string $id): RepairRequestResource
    {
        return $this->transition($request, $id, TransitionAction::Return);
    }

    private function transition(TransitionRequest $request, string $id, TransitionAction $action): RepairRequestResource
    {
        $dto = TransitionDto::fromRequest($request, $id, $action);
        $document = $this->service->transition($request->user(), $dto);

        return new RepairRequestResource($document);
    }

    public function stationDecision(StationDecisionRequest $request, string $id): RepairRequestResource
    {
        $dto = StationDecisionDto::fromRequest($request, $id);
        $document = $this->service->stationDecision($request->user(), $dto);

        return new RepairRequestResource($document);
    }

    public function customerSlotDecision(CustomerSlotDecisionRequest $request, string $id): RepairRequestResource
    {
        $dto = CustomerSlotDecisionDto::fromRequest($request, $id);
        $document = $this->service->customerSlotDecision($request->user(), $dto);

        return new RepairRequestResource($document);
    }

    public function acceptVehicle(AcceptVehicleRequest $request, string $id): RepairRequestResource
    {
        $dto = AcceptVehicleDto::fromRequest($request, $id);
        $document = $this->service->acceptVehicle($request->user(), $dto);

        return new RepairRequestResource($document);
    }

    public function history(Request $request, string $id): RepairRequestHistoryResource
    {
        $document = RepairRequest::resolveFor($request->user(), $id);
        $this->authorize('view', $document);

        return new RepairRequestHistoryResource($document);
    }

    public function workOrder(Request $request, string $id): WorkOrderResource
    {
        $document = RepairRequest::resolveFor($request->user(), $id);
        $this->authorize('view', $document);

        return new WorkOrderResource($document);
    }
}
