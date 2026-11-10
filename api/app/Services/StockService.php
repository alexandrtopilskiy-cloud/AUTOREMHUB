<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RepairStage;
use App\Models\CatalogPart;
use App\Models\RepairRequest;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\DomainInput as Input;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Остатки и резервы ЗЧ организации.
 *
 * Остаток — сумма регистра stock_movements. Резерв создают строки ЗР
 * (этапы 1–5, СТО организации назначена в ЗР) и строки Реализации в статусе
 * «Создан». Резервы покрываются остатком по очереди создания строк: ранний
 * резерв не может «перехватить» более поздний документ. Строка без покрытия
 * — «нет на складе» (в ЗР синяя, в Реализации красная).
 */
final class StockService
{
    private const EPS = 0.0005;

    /** @return array<int, float> catalog_part_id => остаток по организации (или складу) */
    public function onHand(int $organizationId, array $partIds = [], ?int $warehouseId = null): array
    {
        $query = StockMovement::query()
            ->where('organization_id', $organizationId)
            ->selectRaw('catalog_part_id, SUM(quantity) AS qty')
            ->groupBy('catalog_part_id');
        if ($partIds !== []) {
            $query->whereIn('catalog_part_id', $partIds);
        }
        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->pluck('qty', 'catalog_part_id')->map(fn ($qty) => round((float) $qty, 3))->all();
    }

    /**
     * Все действующие резервы организации, по очереди создания.
     *
     * @return array<int, array{key: string, partId: int, qty: float, requestId: int|null, documentId: int|null}>
     */
    public function demands(int $organizationId, array $partIds = []): array
    {
        $requestRows = DB::table('request_parts as rp')
            ->join('repair_requests as r', 'r.id', '=', 'rp.repair_request_id')
            ->join('stations as s', 's.id', '=', 'r.assigned_station_id')
            ->where('s.organization_id', $organizationId)
            ->whereBetween('r.stage', [RepairStage::ManagerRouting->value, RepairStage::RepairInProgress->value])
            ->whereNotNull('rp.catalog_part_id')
            ->whereRaw('rp.quantity - rp.returned_quantity > 0')
            ->when($partIds !== [], fn ($q) => $q->whereIn('rp.catalog_part_id', $partIds))
            ->selectRaw("'rp:' || rp.id AS key, rp.catalog_part_id, rp.quantity - rp.returned_quantity AS qty, rp.repair_request_id AS request_id, NULL::bigint AS document_id, rp.created_at, rp.id")
            ->get();
        $saleRows = DB::table('stock_document_items as i')
            ->join('stock_documents as d', 'd.id', '=', 'i.stock_document_id')
            ->where('d.organization_id', $organizationId)
            ->where('d.type', StockDocument::SALE)
            ->where('d.status', StockDocument::CREATED)
            ->when($partIds !== [], fn ($q) => $q->whereIn('i.catalog_part_id', $partIds))
            ->selectRaw("'si:' || i.id AS key, i.catalog_part_id, i.quantity AS qty, NULL::bigint AS request_id, d.id AS document_id, i.created_at, i.id")
            ->get();

        return $requestRows->concat($saleRows)
            ->sortBy([['created_at', 'asc'], ['key', 'asc']])
            ->values()
            ->map(fn ($row): array => [
                'key' => (string) $row->key,
                'partId' => (int) $row->catalog_part_id,
                'qty' => round((float) $row->qty, 3),
                'requestId' => $row->request_id !== null ? (int) $row->request_id : null,
                'documentId' => $row->document_id !== null ? (int) $row->document_id : null,
            ])
            ->all();
    }

    /**
     * Покрытие резервов остатком по очереди.
     *
     * @return array{onHand: array<int, float>, reserved: array<int, float>, rows: array<string, array{need: float, covered: float, sufficient: bool}>}
     */
    public function allocate(int $organizationId, array $partIds = []): array
    {
        $onHand = $this->onHand($organizationId, $partIds);
        $left = $onHand;
        $reserved = [];
        $rows = [];
        foreach ($this->demands($organizationId, $partIds) as $demand) {
            $part = $demand['partId'];
            $available = max(0.0, $left[$part] ?? 0.0);
            $covered = min($available, $demand['qty']);
            $left[$part] = round($available - $covered, 3);
            $reserved[$part] = round(($reserved[$part] ?? 0.0) + $demand['qty'], 3);
            $rows[$demand['key']] = [
                'need' => $demand['qty'],
                'covered' => round($covered, 3),
                'sufficient' => $covered + self::EPS >= $demand['qty'],
            ];
        }

        return ['onHand' => $onHand, 'reserved' => $reserved, 'rows' => $rows];
    }

    /** Организация склада, с которого ЗР получает ЗЧ: организация назначенной СТО. */
    public function requestOrganizationId(RepairRequest $request): ?int
    {
        if (!$request->assigned_station_id) {
            return null;
        }

        return $request->stationRecord?->organization_id;
    }

    /**
     * Наличие на складе для формы ЗР: варианты ЗЧ с остатком и состояние строк.
     *
     * @return array<string, mixed>
     */
    public function requestStock(RepairRequest $request): array
    {
        $organizationId = $this->requestOrganizationId($request);
        if ($organizationId === null) {
            return ['available' => false, 'parts' => [], 'rows' => []];
        }
        $allocation = $this->allocate($organizationId);
        $ownKeys = $request->parts->mapWithKeys(fn ($part) => ['rp:' . $part->id => $part])->all();
        $otherReserved = [];
        foreach ($this->demands($organizationId) as $demand) {
            if (!isset($ownKeys[$demand['key']])) {
                $otherReserved[$demand['partId']] = ($otherReserved[$demand['partId']] ?? 0) + $demand['qty'];
            }
        }
        $partIds = array_unique([...array_keys($allocation['onHand']), ...$request->parts->pluck('catalog_part_id')->filter()->all()]);
        $catalog = CatalogPart::query()->whereIn('id', $partIds)->get()->keyBy('id');
        $prices = $this->nextPrices($organizationId, array_values($partIds));
        $parts = [];
        foreach ($partIds as $partId) {
            $model = $catalog->get($partId);
            if ($model === null) {
                continue;
            }
            $onHand = $allocation['onHand'][$partId] ?? 0.0;
            $parts[$model->uuid] = [
                'onHand' => $onHand,
                'free' => round(max(0, $onHand - ($otherReserved[$partId] ?? 0)), 3),
                'salePrice' => $prices[$partId]['salePrice'] ?? null,
                'purchasePrice' => $prices[$partId]['purchasePrice'] ?? null,
                'supplierId' => $prices[$partId]['supplierId'] ?? '',
            ];
        }
        $rows = [];
        $writtenOff = $this->isWrittenOff($request);
        foreach ($request->parts as $part) {
            $state = $allocation['rows']['rp:' . $part->id] ?? null;
            $need = round((float) $part->quantity - (float) $part->returned_quantity, 3);
            $rows[$part->uuid] = match (true) {
                $writtenOff => ['need' => $need, 'covered' => $need, 'sufficient' => true, 'writtenOff' => true],
                $state === null => ['need' => $need, 'covered' => 0.0, 'sufficient' => $need <= 0, 'writtenOff' => false],
                default => [...$state, 'writtenOff' => false],
            };
        }

        return ['available' => true, 'reserving' => $request->stage->value >= RepairStage::ManagerRouting->value && $request->stage->value <= RepairStage::RepairInProgress->value, 'parts' => $parts, 'rows' => $rows];
    }

    /**
     * Строки ЗР, которых не хватает на складе для списания (проверка перед
     * переходом на этап «ТС готово к выдаче Заказчику»).
     *
     * @return array<int, string>
     */
    public function missingForRequest(RepairRequest $request): array
    {
        $organizationId = $this->requestOrganizationId($request);
        $missing = [];
        $allocation = $organizationId !== null ? $this->allocate($organizationId) : ['rows' => []];
        foreach ($request->parts()->get() as $part) {
            $need = round((float) $part->quantity - (float) $part->returned_quantity, 3);
            if ($need <= 0) {
                continue;
            }
            $state = $allocation['rows']['rp:' . $part->id] ?? null;
            if ($part->catalog_part_id === null || $state === null || !$state['sufficient']) {
                $covered = $state['covered'] ?? 0;
                $missing[] = sprintf('%s%s — нужно %s, на складе доступно %s', $part->name, $part->article ? ' (' . $part->article . ')' : '', self::qty($need), self::qty($covered));
            }
        }

        return $missing;
    }

    public function assertRequestCovered(RepairRequest $request): void
    {
        $missing = $this->missingForRequest($request);
        if ($missing !== []) {
            Input::fail('Недостаточно ЗЧ на складе для их реализации через ЗР: ' . implode('; ', $missing), 'parts');
        }
    }

    /** Списание ЗЧ ЗР со складов СТО (переход на этап «ТС готово к выдаче»). */
    public function writeOffRequest(RepairRequest $request): void
    {
        $organizationId = $this->requestOrganizationId($request);
        if ($organizationId === null || $this->isWrittenOff($request)) {
            return;
        }
        $warehouses = Warehouse::query()->where('organization_id', $organizationId)->orderBy('id')->pluck('id')->all();
        foreach ($request->parts()->orderBy('id')->get() as $part) {
            $need = round((float) $part->quantity - (float) $part->returned_quantity, 3);
            if ($need <= 0 || $part->catalog_part_id === null) {
                continue;
            }
            $byWarehouse = StockMovement::query()
                ->where('organization_id', $organizationId)
                ->where('catalog_part_id', $part->catalog_part_id)
                ->whereIn('warehouse_id', $warehouses)
                ->selectRaw('warehouse_id, SUM(quantity) AS qty')
                ->groupBy('warehouse_id')
                ->orderByRaw('SUM(quantity) DESC')
                ->orderBy('warehouse_id')
                ->get();
            foreach ($byWarehouse as $row) {
                if ($need <= self::EPS) {
                    break;
                }
                $take = round(min($need, (float) $row->qty), 3);
                if ($take <= 0) {
                    continue;
                }
                $cost = $this->issueCost($organizationId, (int) $part->catalog_part_id, (int) $row->warehouse_id, $take);
                $this->move($request->tenant_id, $organizationId, (int) $row->warehouse_id, (int) $part->catalog_part_id, -$take, $cost, now()->toImmutable(), StockMovement::SOURCE_REQUEST, $request->id, $part->id);
                $need = round($need - $take, 3);
            }
            if ($need > self::EPS) {
                Input::fail('Недостаточно ЗЧ на складе для их реализации через ЗР: ' . $part->name, 'parts');
            }
        }
    }

    /** Возврат ЗР на этап «В работе»: списание отменяется, ЗЧ снова в резерве. */
    public function cancelRequestWriteOff(RepairRequest $request): void
    {
        StockMovement::query()
            ->where('source_type', StockMovement::SOURCE_REQUEST)
            ->where('source_id', $request->id)
            ->delete();
    }

    public function isWrittenOff(RepairRequest $request): bool
    {
        return StockMovement::query()
            ->where('source_type', StockMovement::SOURCE_REQUEST)
            ->where('source_id', $request->id)
            ->exists();
    }

    public function move(int $tenantId, int $organizationId, int $warehouseId, int $partId, float $quantity, float $unitCost, CarbonImmutable $at, string $sourceType, int $sourceId, ?int $sourceItemId): void
    {
        StockMovement::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'catalog_part_id' => $partId,
            'quantity' => round($quantity, 3),
            'unit_cost' => round($unitCost, 2),
            'occurred_at' => $at,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_item_id' => $sourceItemId,
        ]);
    }

    /**
     * Партии приходов по ЗЧ в порядке оприходования: количество, цена закупа,
     * цена реализации и поставщик. Основа ФИФО / ЛИФО.
     *
     * @return array<int, array<int, array{qty: float, cost: float, sale: float|null, supplierId: int|null, supplierUuid: string}>>
     */
    public function lots(int $organizationId, array $partIds = [], ?int $warehouseId = null, ?CarbonImmutable $until = null): array
    {
        $rows = DB::table('stock_movements as m')
            ->leftJoin('stock_document_items as i', 'i.id', '=', 'm.source_item_id')
            ->leftJoin('stock_documents as d', 'd.id', '=', 'm.source_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->where('m.organization_id', $organizationId)
            ->where('m.source_type', StockMovement::SOURCE_RECEIPT)
            ->where('m.quantity', '>', 0)
            ->when($partIds !== [], fn ($q) => $q->whereIn('m.catalog_part_id', $partIds))
            ->when($warehouseId !== null, fn ($q) => $q->where('m.warehouse_id', $warehouseId))
            ->when($until !== null, fn ($q) => $q->where('m.occurred_at', '<=', $until))
            ->orderBy('m.occurred_at')
            ->orderBy('m.id')
            ->get(['m.catalog_part_id', 'm.quantity', 'm.unit_cost', 'i.sale_price', 's.id as supplier_id', 's.uuid as supplier_uuid']);
        $lots = [];
        foreach ($rows as $row) {
            $lots[(int) $row->catalog_part_id][] = [
                'qty' => (float) $row->quantity,
                'cost' => (float) $row->unit_cost,
                'sale' => $row->sale_price !== null ? (float) $row->sale_price : null,
                'supplierId' => $row->supplier_id !== null ? (int) $row->supplier_id : null,
                'supplierUuid' => (string) ($row->supplier_uuid ?? ''),
            ];
        }

        return $lots;
    }

    /** @return array<int, float> catalog_part_id => списано (Реализации и ЗР) */
    public function issued(int $organizationId, array $partIds = [], ?int $warehouseId = null, ?CarbonImmutable $until = null): array
    {
        return StockMovement::query()
            ->where('organization_id', $organizationId)
            ->where('quantity', '<', 0)
            ->when($partIds !== [], fn ($q) => $q->whereIn('catalog_part_id', $partIds))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($until !== null, fn ($q) => $q->where('occurred_at', '<=', $until))
            ->groupBy('catalog_part_id')
            ->selectRaw('catalog_part_id, -SUM(quantity) AS qty')
            ->pluck('qty', 'catalog_part_id')
            ->map(fn ($qty): float => round((float) $qty, 3))
            ->all();
    }

    /**
     * Остаток партий после списания $qty: ФИФО списывает с самых ранних,
     * ЛИФО — с самых поздних. Порядок партий сохраняется.
     *
     * @param array<int, array<string, mixed>> $lots
     * @return array<int, array<string, mixed>>
     */
    public static function remainingLots(array $lots, float $qty, string $method): array
    {
        $ordered = $method === 'lifo' ? array_reverse($lots) : $lots;
        $left = [];
        foreach ($ordered as $lot) {
            if ($qty > self::EPS) {
                $take = min($qty, $lot['qty']);
                $qty -= $take;
                $lot['qty'] = round($lot['qty'] - $take, 3);
            }
            if ($lot['qty'] > self::EPS) {
                $left[] = $lot;
            }
        }

        return $method === 'lifo' ? array_reverse($left) : $left;
    }

    /** Средневзвешенная величина поля партий (cost или sale). */
    private static function weighted(array $lots, string $field): ?float
    {
        $qty = 0.0;
        $amount = 0.0;
        foreach ($lots as $lot) {
            if ($lot[$field] === null) {
                continue;
            }
            $qty += $lot['qty'];
            $amount += $lot['qty'] * $lot[$field];
        }

        return $qty > 0 ? round($amount / $qty, 2) : null;
    }

    /**
     * Себестоимость списания $qty единиц по методу организации: ФИФО —
     * по самым ранним остаткам, ЛИФО — по самым поздним, иначе средневзвешенная.
     */
    public function issueCost(int $organizationId, int $partId, ?int $warehouseId, float $qty): float
    {
        $method = WarehouseService::costMethod($organizationId);
        $lots = $this->lots($organizationId, [$partId], $warehouseId)[$partId] ?? [];
        if ($method === 'average' || $lots === []) {
            return $this->averageCost($organizationId, $partId, $warehouseId);
        }
        $remaining = self::remainingLots($lots, $this->issued($organizationId, [$partId], $warehouseId)[$partId] ?? 0.0, $method);
        $taken = [];
        $need = $qty;
        foreach ($method === 'lifo' ? array_reverse($remaining) : $remaining as $lot) {
            if ($need <= self::EPS) {
                break;
            }
            $take = min($need, $lot['qty']);
            $taken[] = ['qty' => $take, 'cost' => $lot['cost']];
            $need -= $take;
        }

        return self::weighted($taken, 'cost') ?? $this->averageCost($organizationId, $partId, $warehouseId);
    }

    /**
     * Цены для новой строки ЗР или Реализации с учётом метода списания:
     * ФИФО — партия, которая будет списана следующей (после списанного и
     * зарезервированного), ЛИФО — самая поздняя из оставшихся, средневзвешенная —
     * средние цены закупа и реализации по приходам. Нет остатка — цены
     * последнего Прихода.
     *
     * @return array<int, array{purchasePrice: float|null, salePrice: float|null, supplierId: string, supplierDbId: int|null}>
     */
    public function nextPrices(int $organizationId, array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }
        $method = WarehouseService::costMethod($organizationId);
        $lots = $this->lots($organizationId, $partIds);
        $issued = $this->issued($organizationId, $partIds);
        $reserved = [];
        foreach ($this->demands($organizationId, $partIds) as $demand) {
            $reserved[$demand['partId']] = ($reserved[$demand['partId']] ?? 0) + $demand['qty'];
        }
        $last = $this->lastReceiptPrices($organizationId, $partIds);
        $result = [];
        foreach ($partIds as $partId) {
            $partLots = $lots[$partId] ?? [];
            $fallback = $last[$partId] ?? null;
            $remaining = self::remainingLots($partLots, ($issued[$partId] ?? 0) + ($reserved[$partId] ?? 0), $method === 'lifo' ? 'lifo' : 'fifo');
            if ($method === 'average' && $partLots !== []) {
                $lastLot = $partLots[count($partLots) - 1];
                $result[$partId] = [
                    'purchasePrice' => self::weighted($partLots, 'cost'),
                    'salePrice' => self::weighted($partLots, 'sale') ?? $fallback['salePrice'] ?? null,
                    'supplierId' => $lastLot['supplierUuid'],
                    'supplierDbId' => $lastLot['supplierId'],
                ];
            } elseif ($remaining !== []) {
                $lot = $method === 'lifo' ? $remaining[count($remaining) - 1] : $remaining[0];
                $result[$partId] = [
                    'purchasePrice' => $lot['cost'],
                    'salePrice' => $lot['sale'] ?? $fallback['salePrice'] ?? null,
                    'supplierId' => $lot['supplierUuid'],
                    'supplierDbId' => $lot['supplierId'],
                ];
            } elseif ($fallback !== null) {
                $result[$partId] = [...$fallback, 'supplierDbId' => Supplier::query()->where('uuid', $fallback['supplierId'])->value('id')];
            }
        }

        return $result;
    }

    /** Средняя цена закупа по приходам (организация или склад). */
    public function averageCost(int $organizationId, int $partId, ?int $warehouseId = null, ?CarbonImmutable $until = null): float
    {
        $row = StockMovement::query()
            ->where('organization_id', $organizationId)
            ->where('catalog_part_id', $partId)
            ->where('source_type', StockMovement::SOURCE_RECEIPT)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($until !== null, fn ($q) => $q->where('occurred_at', '<=', $until))
            ->selectRaw('SUM(quantity) AS qty, SUM(quantity * unit_cost) AS amount')
            ->first();
        $qty = (float) ($row->qty ?? 0);

        return $qty > 0 ? round((float) $row->amount / $qty, 2) : 0.0;
    }

    /**
     * Цены и поставщик последнего проведённого Прихода по каждой ЗЧ.
     *
     * @return array<int, array{purchasePrice: float|null, salePrice: float|null, supplierId: string}>
     */
    public function lastReceiptPrices(int $organizationId, array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }
        $rows = DB::table('stock_document_items as i')
            ->join('stock_documents as d', 'd.id', '=', 'i.stock_document_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->where('d.organization_id', $organizationId)
            ->where('d.type', StockDocument::RECEIPT)
            ->where('d.status', StockDocument::POSTED)
            ->whereIn('i.catalog_part_id', $partIds)
            ->orderBy('d.posted_at')
            ->orderBy('i.id')
            ->get(['i.catalog_part_id', 'i.purchase_price', 'i.sale_price', 's.uuid as supplier_uuid']);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->catalog_part_id] = [
                'purchasePrice' => $row->purchase_price !== null ? (float) $row->purchase_price : null,
                'salePrice' => $row->sale_price !== null ? (float) $row->sale_price : null,
                'supplierId' => (string) ($row->supplier_uuid ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Отчёт «Остатки на складе» на конец даты (23:59:59 по времени сервиса).
     *
     * @return array<int, array<string, mixed>>
     */
    public function balanceReport(int $organizationId, string $date, ?int $warehouseId): array
    {
        $until = CarbonImmutable::createFromFormat('Y-m-d', $date, config('sto.service_timezone'))->endOfDay()->utc();
        $rows = StockMovement::query()
            ->from('stock_movements as m')
            ->join('catalog_parts as p', 'p.id', '=', 'm.catalog_part_id')
            ->where('m.organization_id', $organizationId)
            ->where('m.occurred_at', '<=', $until)
            ->when($warehouseId !== null, fn ($q) => $q->where('m.warehouse_id', $warehouseId))
            ->groupBy('p.id', 'p.uuid', 'p.name', 'p.article', 'p.manufacturer', 'p.unit')
            ->orderBy('p.name')
            ->orderBy('p.article')
            ->selectRaw('p.id, p.uuid, p.name, p.article, p.manufacturer, p.unit,
                SUM(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END) AS incoming,
                SUM(CASE WHEN m.quantity < 0 THEN -m.quantity ELSE 0 END) AS outgoing,
                SUM(m.quantity) AS balance,
                SUM(CASE WHEN m.source_type = ? THEN m.quantity ELSE 0 END) AS receipt_qty,
                SUM(CASE WHEN m.source_type = ? THEN m.quantity * m.unit_cost ELSE 0 END) AS receipt_amount', [StockMovement::SOURCE_RECEIPT, StockMovement::SOURCE_RECEIPT])
            ->get();

        $method = WarehouseService::costMethod($organizationId);
        $lots = $method === 'average' ? [] : $this->lots($organizationId, $rows->pluck('id')->map(fn ($id) => (int) $id)->all(), $warehouseId, $until);

        return $rows->map(function ($row) use ($method, $lots): array {
            $balance = round((float) $row->balance, 3);
            $cost = (float) $row->receipt_qty > 0 ? (float) $row->receipt_amount / (float) $row->receipt_qty : 0.0;
            if ($method !== 'average' && $balance > 0) {
                // ФИФО: в остатке поздние партии, ЛИФО — ранние.
                $remaining = self::remainingLots($lots[(int) $row->id] ?? [], round((float) $row->outgoing, 3), $method);
                $cost = self::weighted($remaining, 'cost') ?? $cost;
            }

            return [
                'catalogPartId' => $row->uuid,
                'name' => $row->name,
                'article' => $row->article,
                'manufacturer' => (string) $row->manufacturer,
                'unit' => $row->unit,
                'incoming' => round((float) $row->incoming, 3),
                'outgoing' => round((float) $row->outgoing, 3),
                'balance' => $balance,
                'averageCost' => round($cost, 2),
                'amount' => round($balance * $cost, 2),
            ];
        })->all();
    }

    public static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }

    /** Поставщик по uuid в пределах контура. */
    public static function supplier(int $tenantId, string $uuid): ?Supplier
    {
        return Supplier::query()->where('tenant_id', $tenantId)->where('uuid', $uuid)->first();
    }
}
