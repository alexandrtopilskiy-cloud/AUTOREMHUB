<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrganizationKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockDocumentPayment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\DomainInput as Input;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Документы Приход (PRH-000001) и Реализация (RLZ-000001).
 *
 * Статусы: «Создан» — только информация (Реализация резервирует ЗЧ),
 * «Проведен» — Приход добавляет ЗЧ на склад, Реализация списывает.
 */
final class StockDocumentService
{
    public const MAX_ITEMS = 500;

    public function __construct(
        private readonly StockService $stock,
        private readonly WarehouseService $warehouses,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function index(User $user, string $type): array
    {
        WarehouseService::assertAccess($user);
        $this->assertType($type);

        return StockDocument::query()
            ->where('organization_id', $user->organization_id)
            ->where('type', $type)
            ->with(['warehouse', 'supplier', 'customer', 'vehicle', 'author', 'items'])
            ->withSum('payments as paid_sum', 'amount')
            ->orderByDesc('document_date')
            ->orderByDesc('sequence')
            ->get()
            ->map(fn (StockDocument $doc): array => $this->summary($doc))
            ->all();
    }

    /** @return array<string, mixed> */
    public function show(User $user, string $id): array
    {
        return $this->present($this->find($user, $id));
    }

    /** @return array<string, mixed> */
    public function save(User $user, ?string $id, array $payload): array
    {
        WarehouseService::assertAccess($user);

        return DB::transaction(function () use ($user, $id, $payload): array {
            $doc = $id !== null ? $this->find($user, $id, true) : null;
            $type = $doc?->type ?? (string) ($payload['type'] ?? '');
            $this->assertType($type);
            if ($doc?->isPosted()) {
                Input::fail('Проведённый документ изменять нельзя. Сначала отмените проведение.');
            }
            Input::validate($payload, [
                'type' => ['sometimes', 'in:receipt,sale'],
                'revision' => ['sometimes', 'integer'],
                'documentDate' => ['required', 'date_format:Y-m-d'],
                'warehouseId' => ['required', 'uuid'],
                'supplierId' => Input::uuidOrEmpty(),
                'customerId' => Input::uuidOrEmpty(),
                'vehicleId' => Input::uuidOrEmpty(),
                'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'items' => ['present', 'array', 'list', 'max:' . self::MAX_ITEMS],
                'items.*' => ['array:id,catalogPartId,qty,purchasePrice,salePrice,managerId'],
                'items.*.id' => Input::uuidOrEmpty(),
                'items.*.catalogPartId' => ['required', 'uuid'],
                'items.*.qty' => ['required', 'numeric', 'min:0.001', 'max:1000000', Input::numeric(3)],
                'items.*.purchasePrice' => ['nullable', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
                'items.*.salePrice' => ['nullable', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
                'items.*.managerId' => Input::uuidOrEmpty(),
            ], [
                'items.*.catalogPartId.required' => 'Выберите запчасть из справочника',
            ], [
                'documentDate' => 'дата документа',
                'warehouseId' => 'склад',
                'items.*.qty' => 'количество',
                'items.*.purchasePrice' => 'цена прихода',
                'items.*.salePrice' => 'цена реализации',
            ]);
            if ($doc !== null && isset($payload['revision']) && (int) $payload['revision'] !== $doc->revision) {
                throw new ApiException(409, 'CONFLICT', 'Документ изменён другим пользователем. Обновите его.');
            }
            $warehouse = $this->warehouses->find($user, $payload['warehouseId']);
            $doc ??= new StockDocument([
                'tenant_id' => $user->tenantId(),
                'organization_id' => $user->organization_id,
                'type' => $type,
                'status' => StockDocument::CREATED,
                'created_by' => $user->id,
            ]);
            $doc->document_date = $payload['documentDate'];
            $doc->warehouse_id = $warehouse->id;
            $doc->comment = trim((string) ($payload['comment'] ?? '')) ?: null;
            if ($type === StockDocument::RECEIPT) {
                $supplier = ($payload['supplierId'] ?? '') !== ''
                    ? Supplier::query()->where('tenant_id', $user->tenantId())->where('uuid', $payload['supplierId'])->first()
                    : null;
                if ($supplier === null) {
                    Input::fail('Выберите поставщика', 'supplierId');
                }
                $doc->supplier_id = $supplier->id;
            } else {
                [$customer, $vehicle] = $this->customerAndVehicle($user, (string) ($payload['customerId'] ?? ''), (string) ($payload['vehicleId'] ?? ''));
                $doc->customer_organization_id = $customer->id;
                $doc->vehicle_id = $vehicle->id;
            }
            $paid = $doc->exists ? $this->paidAmount($doc) : 0.0;
            if ($paid > 0 && $doc->isDirty(['supplier_id', 'customer_organization_id'])) {
                Input::fail($type === StockDocument::RECEIPT
                    ? 'По документу есть оплаты — сменить поставщика нельзя. Сначала удалите оплаты.'
                    : 'По документу есть оплаты — сменить Заказчика нельзя. Сначала удалите оплаты.');
            }
            if (!$doc->exists) {
                $this->assignNumber($doc);
            } else {
                $doc->revision++;
            }
            $doc->save();
            $this->replaceItems($user, $doc, $payload['items']);
            if ($paid > 0 && $this->documentAmount($doc->fresh('items')) + 0.004 < $paid) {
                Input::fail('Сумма документа меньше уже оплаченной (' . number_format($paid, 2, ',', ' ') . ' ₽). Сначала удалите лишние оплаты.');
            }

            return $this->present($doc->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function post(User $user, string $id): array
    {
        WarehouseService::assertAccess($user);

        return DB::transaction(function () use ($user, $id): array {
            $doc = $this->find($user, $id, true);
            if ($doc->isPosted()) {
                Input::fail('Документ уже проведён');
            }
            $items = $doc->items()->with('catalogPart')->get();
            if ($items->isEmpty()) {
                Input::fail('Добавьте в документ хотя бы одну запчасть');
            }
            $at = $this->movementTime($doc);
            if ($doc->type === StockDocument::RECEIPT) {
                foreach ($items as $item) {
                    if ($item->purchase_price === null) {
                        Input::fail('Укажите цену прихода: ' . $item->catalogPart->name, 'items');
                    }
                    if ($item->sale_price === null) {
                        $price = $this->warehouses->salePrice($doc->organization_id, (float) $item->purchase_price);
                        if ($price === null) {
                            Input::fail('Укажите цену реализации или диапазон наценки в Настройках: ' . $item->catalogPart->name, 'items');
                        }
                        $item->sale_price = $price;
                        $item->save();
                    }
                    $this->stock->move($doc->tenant_id, $doc->organization_id, $doc->warehouse_id, $item->catalog_part_id, (float) $item->quantity, (float) $item->purchase_price, $at, StockMovement::SOURCE_RECEIPT, $doc->id, $item->id);
                }
            } else {
                $missing = $this->saleShortages($doc, $items);
                if ($missing !== []) {
                    Input::fail('Недостаточно ЗЧ на складе: ' . implode('; ', $missing), 'items');
                }
                foreach ($items as $item) {
                    $cost = $this->stock->issueCost($doc->organization_id, $item->catalog_part_id, $doc->warehouse_id, (float) $item->quantity);
                    $this->stock->move($doc->tenant_id, $doc->organization_id, $doc->warehouse_id, $item->catalog_part_id, -(float) $item->quantity, $cost, $at, StockMovement::SOURCE_SALE, $doc->id, $item->id);
                }
            }
            $doc->forceFill(['status' => StockDocument::POSTED, 'posted_at' => now(), 'posted_by' => $user->id, 'revision' => $doc->revision + 1])->save();

            return $this->present($doc->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function unpost(User $user, string $id): array
    {
        WarehouseService::assertAccess($user);

        return DB::transaction(function () use ($user, $id): array {
            $doc = $this->find($user, $id, true);
            if (!$doc->isPosted()) {
                Input::fail('Документ не проведён');
            }
            if ($doc->type === StockDocument::RECEIPT) {
                $quantities = [];
                foreach ($doc->items as $item) {
                    $quantities[$item->catalog_part_id] = ($quantities[$item->catalog_part_id] ?? 0) + (float) $item->quantity;
                }
                $onHand = $this->stock->onHand($doc->organization_id, array_keys($quantities), $doc->warehouse_id);
                foreach ($quantities as $partId => $qty) {
                    if (($onHand[$partId] ?? 0) + 0.0005 < $qty) {
                        $part = CatalogPart::query()->find($partId);
                        Input::fail('ЗЧ из документа уже списаны со склада, отменить проведение нельзя: ' . $part?->name);
                    }
                }
            }
            StockMovement::query()->where('source_type', $doc->type === StockDocument::RECEIPT ? StockMovement::SOURCE_RECEIPT : StockMovement::SOURCE_SALE)
                ->where('source_id', $doc->id)
                ->delete();
            $doc->forceFill(['status' => StockDocument::CREATED, 'posted_at' => null, 'posted_by' => null, 'revision' => $doc->revision + 1])->save();

            return $this->present($doc->fresh());
        });
    }

    public function delete(User $user, string $id): void
    {
        WarehouseService::assertAccess($user);
        DB::transaction(function () use ($user, $id): void {
            $doc = $this->find($user, $id, true);
            if ($doc->isPosted()) {
                Input::fail('Проведённый документ удалить нельзя. Сначала отмените проведение.');
            }
            if ($doc->payments()->exists()) {
                Input::fail('По документу есть оплаты — удалить его нельзя. Сначала удалите оплаты.');
            }
            $doc->delete();
        });
    }

    /**
     * Оплата по документу: Приход — Поставщику, Реализация — от Заказчика.
     * Можно частями, но не больше остатка к оплате.
     *
     * @return array<string, mixed>
     */
    public function addPayment(User $user, string $id, array $payload): array
    {
        WarehouseService::assertAccess($user);

        return DB::transaction(function () use ($user, $id, $payload): array {
            $doc = $this->find($user, $id, true);
            Input::validate($payload, [
                'paidOn' => ['required', 'date_format:Y-m-d'],
                'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000', Input::numeric(2)],
                'method' => ['required', 'in:' . implode(',', array_keys(StockDocumentPayment::METHODS))],
                'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ], [], [
                'paidOn' => 'дата оплаты',
                'amount' => 'сумма оплаты',
                'method' => 'способ оплаты',
            ]);
            $doc->load('items');
            $debt = round($this->documentAmount($doc) - $this->paidAmount($doc), 2);
            if ($debt <= 0) {
                Input::fail('Документ уже оплачен полностью', 'amount');
            }
            $amount = round((float) $payload['amount'], 2);
            if ($amount > $debt + 0.004) {
                Input::fail('Сумма оплаты больше остатка к оплате (' . number_format($debt, 2, ',', ' ') . ' ₽)', 'amount');
            }
            $doc->payments()->create([
                'paid_on' => $payload['paidOn'],
                'amount' => $amount,
                'method' => $payload['method'],
                'comment' => trim((string) ($payload['comment'] ?? '')) ?: null,
                'created_by' => $user->id,
            ]);

            return $this->present($doc->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function deletePayment(User $user, string $id, string $paymentId): array
    {
        WarehouseService::assertAccess($user);

        return DB::transaction(function () use ($user, $id, $paymentId): array {
            $doc = $this->find($user, $id, true);
            $payment = $doc->payments()->where('uuid', $paymentId)->first();
            if ($payment === null) {
                throw new ApiException(404, 'NOT_FOUND', 'Оплата не найдена');
            }
            $payment->delete();

            return $this->present($doc->fresh());
        });
    }

    /**
     * Заказчики и их ТС для Реализации. УК — все Заказчики контура,
     * СТО — Заказчики, с которыми СТО работала по ЗР.
     *
     * @return array<string, mixed>
     */
    public function counterparties(User $user): array
    {
        WarehouseService::assertAccess($user);
        $customers = $this->customerQuery($user)->orderBy('name')->get();
        $vehicles = Vehicle::query()->whereIn('organization_id', $customers->pluck('id'))->orderBy('plate')->get();
        $managers = User::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return [
            'customers' => $customers->map(fn (Organization $org): array => ['id' => $org->uuid, 'name' => $org->name])->all(),
            'vehicles' => $vehicles->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->uuid,
                'customerId' => $customers->firstWhere('id', $vehicle->organization_id)?->uuid,
                'name' => $this->vehicleLabel($vehicle),
            ])->all(),
            'managers' => $managers->map(fn (User $manager): array => [
                'id' => $manager->uuid,
                'name' => $manager->fullName() ?: $manager->login,
            ])->all(),
            'currentManagerId' => $user->uuid,
        ];
    }

    /** @return array<string, mixed> */
    public function balance(User $user, string $date, ?string $warehouseId): array
    {
        WarehouseService::assertAccess($user);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || CarbonImmutable::createFromFormat('Y-m-d', $date) === false) {
            Input::fail('Укажите дату отчёта', 'date');
        }
        $warehouse = $warehouseId ? $this->warehouses->find($user, $warehouseId) : null;
        $rows = $this->stock->balanceReport($user->organization_id, $date, $warehouse?->id);

        return [
            'date' => $date,
            'warehouseId' => $warehouse?->uuid ?? '',
            'costMethod' => WarehouseService::costMethod($user->organization_id),
            'costMethodLabel' => \App\Models\WarehouseSetting::COST_METHODS[WarehouseService::costMethod($user->organization_id)],
            'rows' => $rows,
            'totals' => [
                'incoming' => round(array_sum(array_column($rows, 'incoming')), 3),
                'outgoing' => round(array_sum(array_column($rows, 'outgoing')), 3),
                'balance' => round(array_sum(array_column($rows, 'balance')), 3),
                'amount' => round(array_sum(array_column($rows, 'amount')), 2),
            ],
        ];
    }

    private function replaceItems(User $user, StockDocument $doc, array $rows): void
    {
        $existing = $doc->items()->get()->keyBy('uuid');
        $kept = [];
        $managers = User::query()->where('organization_id', $user->organization_id)->get()->keyBy('uuid');
        $prices = null;
        foreach (array_values($rows) as $index => $row) {
            $part = CatalogPart::query()->where('tenant_id', $user->tenantId())->where('uuid', $row['catalogPartId'])->first();
            if ($part === null) {
                Input::fail('Выберите запчасть из справочника', 'items.' . $index . '.catalogPartId');
            }
            $id = (string) ($row['id'] ?? '');
            $item = $id !== '' ? $existing->get($id) : null;
            $item ??= new StockDocumentItem(['stock_document_id' => $doc->id]);
            $managerId = (string) ($row['managerId'] ?? '');
            $manager = $managerId !== '' ? $managers->get($managerId) : $user;
            if ($manager === null) {
                Input::fail('Менеджер должен быть сотрудником вашей организации', 'items.' . $index . '.managerId');
            }
            $purchase = $row['purchasePrice'] ?? null;
            $sale = $row['salePrice'] ?? null;
            if ($doc->type === StockDocument::RECEIPT) {
                if ($purchase === null || $purchase === '') {
                    Input::fail('Укажите цену прихода', 'items.' . $index . '.purchasePrice');
                }
                // Цена реализации: вручную или по наценке из Настроек при сохранении.
                if ($sale === null || $sale === '') {
                    $sale = $this->warehouses->salePrice($doc->organization_id, (float) $purchase);
                }
            } else {
                $purchase = null;
                if ($sale === null || $sale === '') {
                    $prices ??= $this->stock->nextPrices($doc->organization_id, CatalogPart::query()->whereIn('uuid', array_column($rows, 'catalogPartId'))->pluck('id')->all());
                    $sale = $prices[$part->id]['salePrice'] ?? null;
                }
                if ($sale === null) {
                    Input::fail('Укажите цену реализации: ' . $part->name, 'items.' . $index . '.salePrice');
                }
            }
            $item->fill([
                'line_no' => $index + 1,
                'catalog_part_id' => $part->id,
                'quantity' => $row['qty'],
                'purchase_price' => $purchase,
                'sale_price' => $sale,
                'manager_id' => $manager->id,
            ])->save();
            $kept[] = $item->id;
        }
        $doc->items()->whereNotIn('id', $kept)->delete();
    }

    /** @return array<int, string> */
    private function saleShortages(StockDocument $doc, $items): array
    {
        $partIds = $items->pluck('catalog_part_id')->unique()->all();
        $allocation = $this->stock->allocate($doc->organization_id, $partIds);
        $warehouseStock = $this->stock->onHand($doc->organization_id, $partIds, $doc->warehouse_id);
        $needByPart = [];
        $missing = [];
        foreach ($items as $item) {
            $needByPart[$item->catalog_part_id] = ($needByPart[$item->catalog_part_id] ?? 0) + (float) $item->quantity;
            $state = $allocation['rows']['si:' . $item->id] ?? null;
            $enoughInWarehouse = ($warehouseStock[$item->catalog_part_id] ?? 0) + 0.0005 >= $needByPart[$item->catalog_part_id];
            if ($state === null || !$state['sufficient'] || !$enoughInWarehouse) {
                $missing[] = sprintf('%s — нужно %s, доступно %s', $item->catalogPart->name, StockService::qty((float) $item->quantity), StockService::qty(min($state['covered'] ?? 0, max(0, $warehouseStock[$item->catalog_part_id] ?? 0))));
            }
        }

        return $missing;
    }

    private function movementTime(StockDocument $doc): CarbonImmutable
    {
        $timezone = config('sto.service_timezone');
        $now = CarbonImmutable::now($timezone);
        $date = $doc->document_date->format('Y-m-d');

        return $date === $now->format('Y-m-d')
            ? $now->utc()
            : CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 12:00:00', $timezone)->utc();
    }

    private function assignNumber(StockDocument $doc): void
    {
        // Свой счётчик у каждой организации (УК и каждая СТО ведут свой складской учёт).
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('stock_documents:' . $doc->organization_id . ':' . $doc->type)]);
        $sequence = (int) StockDocument::query()->where('organization_id', $doc->organization_id)->where('type', $doc->type)->max('sequence') + 1;
        $doc->sequence = $sequence;
        $doc->number = StockDocument::prefix($doc->type) . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** @return array{0: Organization, 1: Vehicle} */
    private function customerAndVehicle(User $user, string $customerId, string $vehicleId): array
    {
        $customer = $customerId !== '' ? $this->customerQuery($user)->where('uuid', $customerId)->first() : null;
        if ($customer === null) {
            Input::fail('Выберите Заказчика', 'customerId');
        }
        $vehicle = $vehicleId !== '' ? Vehicle::query()->where('organization_id', $customer->id)->where('uuid', $vehicleId)->first() : null;
        if ($vehicle === null) {
            Input::fail('Выберите ТС Заказчика', 'vehicleId');
        }

        return [$customer, $vehicle];
    }

    private function customerQuery(User $user)
    {
        $query = Organization::query()
            ->where('tenant_id', $user->tenantId())
            ->where('kind', OrganizationKind::Customer->value);
        if ($user->role === UserRole::Station) {
            $query->whereIn('id', RepairRequest::query()
                ->select('customer_organization_id')
                ->whereIn('assigned_station_id', \App\Models\Station::query()->select('id')->where('organization_id', $user->organization_id)));
        }

        return $query;
    }

    private function documentAmount(StockDocument $doc): float
    {
        return round((float) $doc->items->sum(fn (StockDocumentItem $item): float => (float) $item->quantity * (float) ($doc->type === StockDocument::RECEIPT ? $item->purchase_price : $item->sale_price)), 2);
    }

    private function paidAmount(StockDocument $doc): float
    {
        $sum = $doc->getAttribute('paid_sum');
        if ($sum === null && !array_key_exists('paid_sum', $doc->getAttributes())) {
            $sum = $doc->relationLoaded('payments') ? $doc->payments->sum('amount') : $doc->payments()->sum('amount');
        }

        return round((float) $sum, 2);
    }

    private function find(User $user, string $id, bool $lock = false): StockDocument
    {
        WarehouseService::assertAccess($user);
        $doc = StockDocument::query()
            ->where('organization_id', $user->organization_id)
            ->where('uuid', $id)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
        if ($doc === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Документ не найден');
        }

        return $doc;
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, [StockDocument::RECEIPT, StockDocument::SALE], true)) {
            Input::fail('Неизвестный тип документа', 'type');
        }
    }

    private function vehicleLabel(Vehicle $vehicle): string
    {
        return trim(implode(' · ', array_filter([
            trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: $vehicle->name,
            $vehicle->plate,
            $vehicle->vin,
        ])));
    }

    /** @return array<string, mixed> */
    private function summary(StockDocument $doc): array
    {
        $items = $doc->items;
        $amount = $this->documentAmount($doc);
        $paid = $this->paidAmount($doc);
        $debt = max(0.0, round($amount - $paid, 2));
        $paymentStatus = $paid <= 0 ? 'unpaid' : ($debt > 0 ? 'partial' : 'paid');

        return [
            'id' => $doc->uuid,
            'type' => $doc->type,
            'number' => $doc->number,
            'documentDate' => $doc->document_date->format('Y-m-d'),
            'status' => $doc->status,
            'statusLabel' => $doc->isPosted() ? 'Проведен' : 'Создан',
            'warehouseId' => $doc->warehouse?->uuid ?? '',
            'warehouse' => $doc->warehouse?->name ?? '',
            'supplierId' => $doc->supplier?->uuid ?? '',
            'supplier' => $doc->supplier?->name ?? '',
            'customerId' => $doc->customer?->uuid ?? '',
            'customer' => $doc->customer?->name ?? '',
            'vehicleId' => $doc->vehicle?->uuid ?? '',
            'vehicle' => $doc->vehicle ? $this->vehicleLabel($doc->vehicle) : '',
            'positions' => $items->count(),
            'quantity' => round((float) $items->sum(fn ($item) => (float) $item->quantity), 3),
            'amount' => round($amount, 2),
            'paid' => round($paid, 2),
            'debt' => $debt,
            'paymentStatus' => $paymentStatus,
            'paymentStatusLabel' => ['unpaid' => 'Не оплачен', 'partial' => 'Оплачен частично', 'paid' => 'Оплачен'][$paymentStatus],
            'author' => $doc->author ? ($doc->author->fullName() ?: $doc->author->login) : '',
            'createdAt' => $doc->created_at?->toISOString(),
            'postedAt' => $doc->posted_at?->toISOString(),
            'revision' => $doc->revision,
        ];
    }

    /** @return array<string, mixed> */
    private function present(StockDocument $doc): array
    {
        $doc->load(['warehouse', 'supplier', 'customer', 'vehicle', 'author', 'items.catalogPart', 'items.manager', 'payments.author']);
        $states = [];
        $warehouseStock = [];
        if ($doc->type === StockDocument::SALE) {
            $partIds = $doc->items->pluck('catalog_part_id')->unique()->all();
            $states = $doc->isPosted() ? [] : $this->stock->allocate($doc->organization_id, $partIds)['rows'];
            $warehouseStock = $this->stock->onHand($doc->organization_id, $partIds, $doc->warehouse_id);
        }
        $needByPart = [];

        return [
            ...$this->summary($doc),
            'comment' => (string) ($doc->comment ?? ''),
            'payments' => $doc->payments->map(fn (StockDocumentPayment $payment): array => [
                'id' => $payment->uuid,
                'paidOn' => $payment->paid_on->format('Y-m-d'),
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'methodLabel' => StockDocumentPayment::METHODS[$payment->method] ?? $payment->method,
                'comment' => (string) ($payment->comment ?? ''),
                'author' => $payment->author ? ($payment->author->fullName() ?: $payment->author->login) : '',
                'createdAt' => $payment->created_at?->toISOString(),
            ])->all(),
            'paymentMethods' => collect(StockDocumentPayment::METHODS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'items' => $doc->items->map(function (StockDocumentItem $item) use ($doc, $states, $warehouseStock, &$needByPart): array {
                $part = $item->catalogPart;
                $row = [
                    'id' => $item->uuid,
                    'catalogPartId' => $part->uuid,
                    'name' => $part->name,
                    'article' => $part->article,
                    'manufacturer' => (string) $part->manufacturer,
                    'unit' => $part->unit,
                    'qty' => (float) $item->quantity,
                    'purchasePrice' => $item->purchase_price !== null ? (float) $item->purchase_price : null,
                    'salePrice' => $item->sale_price !== null ? (float) $item->sale_price : null,
                    'amount' => round((float) $item->quantity * (float) ($doc->type === StockDocument::RECEIPT ? $item->purchase_price : $item->sale_price), 2),
                    'managerId' => $item->manager?->uuid ?? '',
                    'manager' => $item->manager ? ($item->manager->fullName() ?: $item->manager->login) : '',
                ];
                if ($doc->type === StockDocument::SALE) {
                    $needByPart[$item->catalog_part_id] = ($needByPart[$item->catalog_part_id] ?? 0) + (float) $item->quantity;
                    $state = $states['si:' . $item->id] ?? null;
                    $inWarehouse = (float) ($warehouseStock[$item->catalog_part_id] ?? 0);
                    $row['onHand'] = $inWarehouse;
                    $row['sufficient'] = $doc->isPosted() || (($state['sufficient'] ?? false) && $inWarehouse + 0.0005 >= $needByPart[$item->catalog_part_id]);
                    $row['stockLabel'] = $doc->isPosted() ? 'Списано' : ($row['sufficient'] ? 'В резерве' : 'Недостаточно на складе');
                }

                return $row;
            })->all(),
        ];
    }
}
