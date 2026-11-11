<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseSetting;
use App\Support\DomainInput as Input;
use Illuminate\Support\Facades\DB;

/**
 * Настройки → Складской учёт: склады организации и наценка на товары.
 * Доступно УК и СТО; каждая организация ведёт свои склады и свою наценку.
 */
final class WarehouseService
{
    public const MAX_TIERS = 10;

    public static function assertAccess(User $user): void
    {
        if (!in_array($user->role, [UserRole::Manager, UserRole::Station], true)) {
            throw new ApiException(403, 'FORBIDDEN', 'Складской учёт доступен УК и СТО');
        }
    }

    /** @return array<string, mixed> */
    public function settings(User $user): array
    {
        self::assertAccess($user);
        $setting = $this->setting($user);

        return [
            'warehouses' => $this->warehouses($user),
            'markup' => $this->presentMarkup($setting),
            'costMethod' => $setting->cost_method ?? 'average',
            'costMethods' => collect(WarehouseSetting::COST_METHODS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
        ];
    }

    /** Метод списания со склада: ФИФО, ЛИФО или средневзвешенная цена. */
    public function saveCostMethod(User $user, array $payload): string
    {
        self::assertAccess($user);
        Input::validate($payload, ['method' => ['required', 'in:' . implode(',', array_keys(WarehouseSetting::COST_METHODS))]], [], ['method' => 'метод списания']);
        $setting = $this->setting($user);
        $setting->cost_method = $payload['method'];
        $setting->save();

        return $setting->cost_method;
    }

    public static function costMethod(int $organizationId): string
    {
        return (string) (WarehouseSetting::query()->where('organization_id', $organizationId)->value('cost_method') ?? 'average');
    }

    /**
     * Системный «Склад от УК» СТО: сюда приходуются ЗЧ, перемещённые со
     * склада УК («ЗЧ от УК» в ЗР). Создаётся автоматически.
     */
    public static function fromManagerWarehouse(int $organizationId, int $tenantId): Warehouse
    {
        return DB::transaction(fn (): Warehouse => Warehouse::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'system_code' => Warehouse::FROM_MANAGER],
            ['tenant_id' => $tenantId, 'name' => Warehouse::FROM_MANAGER_NAME],
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function warehouses(User $user): array
    {
        self::assertAccess($user);
        if ($user->role === UserRole::Station) {
            self::fromManagerWarehouse((int) $user->organization_id, (int) $user->tenantId());
        }
        $used = StockDocument::query()->where('organization_id', $user->organization_id)->distinct()->pluck('warehouse_id')->all();

        return Warehouse::query()
            ->where('organization_id', $user->organization_id)
            ->orderByRaw('system_code IS NOT NULL')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->uuid,
                'name' => $warehouse->name,
                'used' => in_array($warehouse->id, $used, true),
                // «Склад от УК»: создаётся по умолчанию, не редактируется.
                'system' => $warehouse->isSystem(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function saveWarehouse(User $user, ?string $id, array $payload): array
    {
        self::assertAccess($user);
        Input::validate($payload, ['name' => ['required', 'string', 'max:255']], [], ['name' => 'наименование склада']);
        $name = trim((string) $payload['name']);
        if ($name === '') {
            Input::fail('Укажите наименование склада', 'name');
        }
        $duplicate = Warehouse::query()
            ->where('organization_id', $user->organization_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($id !== null, fn ($q) => $q->where('uuid', '!=', $id))
            ->exists();
        if ($duplicate) {
            Input::fail('Склад с таким наименованием уже есть', 'name');
        }
        if ($id !== null && $this->find($user, $id)->isSystem()) {
            Input::fail('«' . Warehouse::FROM_MANAGER_NAME . '» создаётся автоматически и не редактируется');
        }
        $warehouse = $id !== null ? $this->find($user, $id) : new Warehouse([
            'tenant_id' => $user->tenantId(),
            'organization_id' => $user->organization_id,
        ]);
        $warehouse->name = $name;
        $warehouse->save();

        return $this->warehouses($user);
    }

    /** @return array<int, array<string, mixed>> */
    public function deleteWarehouse(User $user, string $id): array
    {
        self::assertAccess($user);
        $warehouse = $this->find($user, $id);
        if ($warehouse->isSystem()) {
            Input::fail('«' . Warehouse::FROM_MANAGER_NAME . '» создаётся автоматически, удалить его нельзя');
        }
        $used = StockDocument::query()->where('warehouse_id', $warehouse->id)->exists()
            || StockMovement::query()->where('warehouse_id', $warehouse->id)->exists();
        if ($used) {
            Input::fail('Склад используется в документах Прихода или Реализации — удалить его нельзя');
        }
        $warehouse->delete();

        return $this->warehouses($user);
    }

    public function find(User $user, string $id): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('organization_id', $user->organization_id)
            ->where('uuid', $id)
            ->first();
        if ($warehouse === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Склад не найден');
        }

        return $warehouse;
    }

    /** @return array<string, mixed> */
    public function saveMarkup(User $user, array $payload): array
    {
        self::assertAccess($user);
        Input::validate($payload, [
            'mode' => ['required', 'in:flat,progressive'],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:10000', Input::numeric(2)],
            'tiers' => ['present', 'array', 'list', 'max:' . self::MAX_TIERS],
            'tiers.*' => ['array:from,to,percent'],
            'tiers.*.from' => ['nullable', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
            'tiers.*.to' => ['nullable', 'numeric', 'min:0', 'max:1000000000', Input::numeric(2)],
            'tiers.*.percent' => ['required', 'numeric', 'min:0', 'max:10000', Input::numeric(2)],
        ], [
            'tiers.max' => 'В таблице прогрессии не более ' . self::MAX_TIERS . ' строк',
        ], [
            'percent' => 'наценка, %',
            'tiers.*.percent' => 'наценка, %',
            'tiers.*.from' => 'цена прихода от',
            'tiers.*.to' => 'цена прихода до',
        ]);
        $tiers = [];
        foreach ($payload['tiers'] as $index => $tier) {
            $from = $tier['from'] === null || $tier['from'] === '' ? 0.0 : (float) $tier['from'];
            $to = $tier['to'] === null || $tier['to'] === '' ? null : (float) $tier['to'];
            if ($to !== null && $to < $from) {
                Input::fail('Строка ' . ($index + 1) . ': «Цена прихода до» меньше «Цена прихода от»', 'tiers.' . $index . '.to');
            }
            $tiers[] = ['from' => $from, 'to' => $to, 'percent' => (float) $tier['percent']];
        }
        usort($tiers, fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        for ($i = 1; $i < count($tiers); $i++) {
            $previous = $tiers[$i - 1];
            if ($previous['to'] === null || $previous['to'] >= $tiers[$i]['from']) {
                Input::fail('Диапазоны цен в таблице прогрессии пересекаются', 'tiers');
            }
        }
        if ($payload['mode'] === 'progressive' && $tiers === []) {
            Input::fail('Заполните таблицу прогрессии', 'tiers');
        }
        if ($payload['mode'] === 'flat' && ($payload['percent'] ?? null) === null) {
            Input::fail('Укажите наценку в процентах', 'percent');
        }
        $setting = $this->setting($user);
        $setting->fill([
            'markup_mode' => $payload['mode'],
            'markup_percent' => (float) ($payload['percent'] ?? 0),
            'markup_tiers' => $tiers,
        ])->save();

        return $this->presentMarkup($setting);
    }

    /** Цена реализации по наценке организации; null — диапазон прогрессии не найден. */
    public function salePrice(int $organizationId, float $purchasePrice): ?float
    {
        $setting = WarehouseSetting::query()->where('organization_id', $organizationId)->first();
        if ($setting === null) {
            return null;
        }
        $percent = null;
        if ($setting->markup_mode === 'flat') {
            $percent = (float) $setting->markup_percent;
        } else {
            foreach ($setting->markup_tiers ?? [] as $tier) {
                $from = (float) ($tier['from'] ?? 0);
                $to = $tier['to'] ?? null;
                if ($purchasePrice >= $from && ($to === null || $purchasePrice <= (float) $to)) {
                    $percent = (float) $tier['percent'];
                    break;
                }
            }
        }

        return $percent === null ? null : round($purchasePrice * (1 + $percent / 100), 2);
    }

    private function setting(User $user): WarehouseSetting
    {
        return DB::transaction(fn (): WarehouseSetting => WarehouseSetting::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            ['tenant_id' => $user->tenantId(), 'markup_mode' => 'flat', 'markup_percent' => 0, 'markup_tiers' => [], 'cost_method' => 'average'],
        ));
    }

    /** @return array<string, mixed> */
    private function presentMarkup(WarehouseSetting $setting): array
    {
        return [
            'mode' => $setting->markup_mode,
            'percent' => (float) $setting->markup_percent,
            'tiers' => array_values(array_map(fn (array $tier): array => [
                'from' => (float) ($tier['from'] ?? 0),
                'to' => isset($tier['to']) ? (float) $tier['to'] : null,
                'percent' => (float) $tier['percent'],
            ], $setting->markup_tiers ?? [])),
        ];
    }
}
