<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TransitionAction;
use App\Http\Requests\UpdateRepairRequestRequest;
use App\Models\RepairRequest;
use App\Services\RepairRequestService;
use App\Support\ApiInput;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class RepairRequestController
{
    public function __construct(
        private readonly RepairRequestService $service,
        private readonly ApiPresenter $apiPresenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $requests = RepairRequest::forUser($user)
            ->latest('id')
            ->get();
        $this->apiPresenter->preloadRecommendations($requests, $user);
        $requests = $requests
            ->map(fn (RepairRequest $document) => $this->apiPresenter->document($document, $user))
            ->values();

        return response()->json(['requests' => $requests]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $document = RepairRequest::resolveFor($user, $id);

        return response()->json([
            'request' => $this->apiPresenter->document($document, $user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $document = $this->service->create($user);

        return response()->json(
            ['request' => $this->apiPresenter->document($document, $user)],
            201,
        );
    }

    public function update(UpdateRepairRequestRequest $request, string $id): JsonResponse
    {
        $user = $request->user();

        $document = $this->service->update(
            $user,
            $id,
            ApiInput::revision($request),
            ApiInput::patch($request),
        );

        return response()->json([
            'request' => $this->apiPresenter->document($document, $user),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $document = RepairRequest::resolveFor($request->user(), $id);
        $deleted = $this->service->deleteDrafts(
            $request->user(),
            [$document->uuid],
        );

        return response()->json(['deletedIds' => $deleted]);
    }

    public function destroyDrafts(Request $request): JsonResponse
    {
        $payload = Input::validate($request->json()->all(), [
            'ids'   => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        return response()->json([
            'deletedIds' => $this->service->deleteDrafts($request->user(), $payload['ids']),
        ]);
    }

    public function next(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, TransitionAction::Next);
    }

    public function returnBack(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, TransitionAction::Return);
    }

    public function stationDecision(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['revision', 'decision', 'comment', 'stationSlot']);

        $user = $request->user();
        $document = $this->service->stationDecision(
            $user,
            $id,
            ApiInput::revision($request),
            Arr::only(
                $request->json()->all(),
                ['decision', 'comment', 'stationSlot'],
            ),
        );

        return response()->json([
            'request' => $this->apiPresenter->document($document, $user),
        ]);
    }

    public function customerSlotDecision(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['revision', 'decision', 'slot', 'comment']);

        $user = $request->user();
        $document = $this->service->customerSlotDecision(
            $user,
            $id,
            ApiInput::revision($request),
            Arr::only(
                $request->json()->all(),
                ['decision', 'slot', 'comment'],
            ),
        );

        return response()->json([
            'request' => $this->apiPresenter->document($document, $user),
        ]);
    }

    public function acceptVehicle(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, [
            'revision',
            'mode',
            'attachmentId',
            'mileage',
            'fuelLevel',
            'condition',
            'defectTypes',
            'diagramMarks',
        ]);

        $user = $request->user();
        $document = $this->service->acceptVehicle(
            $user,
            $id,
            ApiInput::revision($request),
            Arr::except($request->json()->all(), ['revision']),
        );

        return response()->json([
            'request' => $this->apiPresenter->document($document, $user),
        ]);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $document = $this->apiPresenter->document(RepairRequest::resolveFor($user, $id), $user);

        return response()->json([
            'history'       => $document['history'] ?? [],
            'statusPeriods' => $document['statusPeriods'] ?? [],
            'approvals'     => $document['approvals'] ?? [],
        ]);
    }

    public function workOrder(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $document = RepairRequest::resolveFor($user, $id);

        return response()->json([
            'workOrder' => $this->apiPresenter->workOrder($document),
        ]);
    }

    /**
     * Выполняет переход заявки (next / return).
     */
    private function transition(
        Request $request,
        string $id,
        TransitionAction $action,
    ): JsonResponse {
        ApiInput::only($request, ['revision', 'comment', 'attachmentIds', 'confirmDeclinedStation']);

        $body = Arr::except($request->json()->all(), ['revision']);
        $user = $request->user();

        $document = $this->service->transition(
            $user,
            $id,
            ApiInput::revision($request),
            $action,
            $body,
        );

        return response()->json([
            'request' => $this->apiPresenter->document($document, $user),
        ]);
    }
}
