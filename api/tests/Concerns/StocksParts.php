<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\CatalogPart;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockDocumentService;
use App\Services\WarehouseService;

/** Оприходование ЗЧ на склад организации пользователя (проведённый Приход). */
trait StocksParts
{
    protected function stockPart(User $user, CatalogPart $part, float $qty, Supplier $supplier, float $purchasePrice = 100, ?float $salePrice = null): array
    {
        $warehouses = app(WarehouseService::class)->warehouses($user);
        if ($warehouses === []) {
            $warehouses = app(WarehouseService::class)->saveWarehouse($user, null, ['name' => 'Основной склад']);
        }
        $documents = app(StockDocumentService::class);
        $doc = $documents->save($user, null, [
            'type' => 'receipt',
            'documentDate' => now(config('sto.service_timezone'))->format('Y-m-d'),
            'warehouseId' => $warehouses[0]['id'],
            'supplierId' => $supplier->uuid,
            'items' => [[
                'catalogPartId' => $part->uuid,
                'qty' => $qty,
                'purchasePrice' => $purchasePrice,
                'salePrice' => $salePrice ?? $purchasePrice * 1.5,
            ]],
        ]);

        return $documents->post($user, $doc['id']);
    }
}
