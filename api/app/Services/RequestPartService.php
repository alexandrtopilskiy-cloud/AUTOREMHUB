<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CatalogPart;
use App\Models\PartMovement;
use App\Models\RepairRequest;
use App\Models\RequestPart;
use App\Models\Supplier;
use App\Enums\RequestPartStatus;
use App\Support\DomainInput as Input;
use Illuminate\Validation\Rule;

/** Replaces editable estimate lines inside the request's transaction. */
final class RequestPartService
{
    public function replace(RepairRequest $request, array $rows): void
    {
        $existing = $request->parts()->lockForUpdate()->get()->keyBy('uuid');
        $kept = [];
        foreach ($rows as $index => $row) {
            if (is_array($row) && empty($row['id']) && empty($row['catalogPartId']) && trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            if (!is_array($row)) {
                Input::fail('Некорректная строка запчастей', 'parts.' . $index);
            }
            Input::validate($row, [
                'id' => Input::uuidOrEmpty(),
                'catalogPartId' => Input::uuidOrEmpty(),
                'name' => ['required', 'string', 'max:500'],
                'article' => ['present', 'string', 'max:100'],
                'unit' => ['required', Rule::in(config('sto.part_units'))],
                'supplierId' => ['required', 'uuid'],
                'qty' => ['required', 'numeric', 'min:0.001', 'max:1000000', Input::numeric(3)],
                'price' => ['required', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
            ]);
            $id = $row['id'] ?? '';
            if ($id !== '' && (!$existing->has($id) || in_array($id, $kept, true))) {
                Input::fail('Позиция не принадлежит заявке или повторяется', 'parts.' . $index . '.id');
            }
            $part = $id !== '' ? $existing->get($id) : new RequestPart(['repair_request_id' => $request->id]);
            $catalogId = $row['catalogPartId'] ?? '';
            $catalog = $catalogId !== '' ? CatalogPart::query()->where('tenant_id', $request->tenant_id)->where('uuid', $catalogId)->firstOrFail() : null;
            if (!$part->exists && (!$catalog || !$catalog->active)) {
                Input::fail('Выберите активную запчасть из справочника', 'parts.' . $index . '.catalogPartId');
            }
            $supplier = Supplier::query()->where('tenant_id', $request->tenant_id)->where('uuid', $row['supplierId'])->firstOrFail();
            $attributes = [
                'catalog_part_id' => $catalog?->id,
                'name' => $catalog?->name ?? trim($row['name']),
                'article' => $catalog?->article ?? $row['article'],
                'unit' => $catalog?->unit ?? $row['unit'],
                'supplier_id' => $supplier->id,
                'quantity' => $row['qty'],
                'price' => $row['price'],
                'manufacturer_snapshot' => $part->exists && $part->catalog_part_id === $catalog?->id ? $part->manufacturer_snapshot : $catalog?->manufacturer,
                'purchase_price' => $part->exists && $part->catalog_part_id === $catalog?->id ? $part->purchase_price : $catalog?->purchase_price,
            ];
            $part->fill($attributes);
            if ($part->isDirty()) {
                $this->assertEditable($part);
                if (!$supplier->active) {
                    Input::fail('Поставщик неактивен', 'parts.' . $index . '.supplierId');
                }
                $part->status = RequestPartStatus::Proposed;
                $part->approved_version = null;
                $part->save();
            }
            $kept[] = $part->uuid;
        }
        foreach ($existing as $part) {
            if (!in_array($part->uuid, $kept, true)) {
                $this->assertEditable($part);
                $part->delete();
            }
        }
    }

    public function assertEditable(RequestPart $part): void
    {
        if ($part->exists && (!$part->status->isEditable() || (float) $part->returned_quantity > 0
            || PartMovement::query()->where('request_part_id', $part->id)->exists())) {
            Input::fail('Заказанную позицию или позицию с движениями изменять нельзя: оформите возврат');
        }
    }
}
