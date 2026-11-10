<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\RepairRequest;
use App\Models\Station;
use App\Services\StockDocumentService;
use App\Services\StockService;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Склад: настройки складского учёта, документы Приход/Реализация, остатки. */
final class WarehouseController
{
    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly StockDocumentService $documents,
    ) {}

    public function settings(Request $request): JsonResponse
    {
        return response()->json($this->warehouses->settings($request->user()));
    }

    public function saveMarkup(Request $request): JsonResponse
    {
        return response()->json(['markup' => $this->warehouses->saveMarkup($request->user(), $request->all())]);
    }

    public function saveCostMethod(Request $request): JsonResponse
    {
        return response()->json(['costMethod' => $this->warehouses->saveCostMethod($request->user(), $request->all())]);
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        return response()->json(['warehouses' => $this->warehouses->saveWarehouse($request->user(), null, $request->all())], 201);
    }

    public function updateWarehouse(Request $request, string $id): JsonResponse
    {
        return response()->json(['warehouses' => $this->warehouses->saveWarehouse($request->user(), $id, $request->all())]);
    }

    public function destroyWarehouse(Request $request, string $id): JsonResponse
    {
        return response()->json(['warehouses' => $this->warehouses->deleteWarehouse($request->user(), $id)]);
    }

    public function counterparties(Request $request): JsonResponse
    {
        return response()->json($this->documents->counterparties($request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->documents->index($request->user(), (string) $request->query('type', ''))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['document' => $this->documents->show($request->user(), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['document' => $this->documents->save($request->user(), null, $request->all())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['document' => $this->documents->save($request->user(), $id, $request->all())]);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        return response()->json(['document' => $this->documents->post($request->user(), $id)]);
    }

    public function unpost(Request $request, string $id): JsonResponse
    {
        return response()->json(['document' => $this->documents->unpost($request->user(), $id)]);
    }

    public function storePayment(Request $request, string $id): JsonResponse
    {
        return response()->json(['document' => $this->documents->addPayment($request->user(), $id, $request->all())], 201);
    }

    public function destroyPayment(Request $request, string $id, string $paymentId): JsonResponse
    {
        return response()->json(['document' => $this->documents->deletePayment($request->user(), $id, $paymentId)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->documents->delete($request->user(), $id);

        return response()->json(['deleted' => true]);
    }

    public function balance(Request $request): JsonResponse
    {
        return response()->json($this->documents->balance(
            $request->user(),
            (string) $request->query('date', now(config('sto.service_timezone'))->format('Y-m-d')),
            $request->query('warehouseId') ? (string) $request->query('warehouseId') : null,
        ));
    }

    /** Наличие ЗЧ на складах СТО, назначенной в ЗР (только УК и СТО). */
    public function requestStock(Request $request, string $id, StockService $stock): JsonResponse
    {
        $user = $request->user();
        if ($user->role === UserRole::Customer) {
            throw new ApiException(403, 'FORBIDDEN', 'Складские остатки Заказчику недоступны');
        }
        if ($id === 'new') {
            $document = new RepairRequest();
            $document->stage = RepairStage::Draft;
            $document->setRelation('parts', collect());
        } else {
            $document = RepairRequest::resolveFor(UserDto::fromUserModel($user), $id);
            $document->loadMissing(['parts', 'stationRecord']);
        }
        if (!$request->has('stationId')) {
            return response()->json($id === 'new' ? $stock->stockFor($document, null) : $stock->requestStock($document));
        }
        // СТО, выбранная в форме, но ещё не сохранённая в ЗР.
        $stationId = (string) $request->query('stationId', '');
        $organizationId = $stationId === '' ? null : Station::query()
            ->where('tenant_id', $user->tenantId())
            ->where('uuid', $stationId)
            ->value('organization_id');

        return response()->json($stock->stockFor($document, $organizationId !== null ? (int) $organizationId : null));
    }
}
