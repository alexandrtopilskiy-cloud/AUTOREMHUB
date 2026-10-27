<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkOrderItemKind;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrderItem>
 */
final class WorkOrderItemFactory extends Factory
{
    protected $model = WorkOrderItem::class;

    public function definition(): array
    {
        return [
            'work_order_id'  => WorkOrder::factory(),
            'kind'           => WorkOrderItemKind::Work,
            'name'           => 'Позиция ' . Str::upper(Str::random(6)),
            'quantity'       => 1.000,
            'price'          => 1500.00,
            'norm_hours'     => 0.000,
            'actual_hours'   => null,
            'is_maintenance' => false,
        ];
    }
}
