<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RepairRequest;

final class RepairRequestCalculator
{
    private const MONEY_SCALE = 2;
    private const QUANTITY_SCALE = 3;

    public function total(RepairRequest $request): string
    {
        $sum = '0.00';

        foreach ($request->workLines()->get() as $work) {
            $sum = bcadd(
                $sum,
                $work->line_amount !== null
                    ? (string) $work->line_amount
                    : $this->lineTotal(
                        (string) $work->quantity,
                        (string) ($work->price ?? 0),
                    ),
                self::MONEY_SCALE,
            );
        }

        foreach ($request->parts()->get() as $part) {
            $remaining = bcsub(
                (string) $part->quantity,
                (string) $part->returned_quantity,
                self::QUANTITY_SCALE,
            );
            $sum = bcadd($sum, $this->lineTotal($remaining, (string) $part->price), self::MONEY_SCALE);
        }

        return $sum;
    }

    private function lineTotal(string $quantity, string $price): string
    {
        return bcadd(
            bcmul($quantity, $price, 5),
            '0.005',
            self::MONEY_SCALE,
        );
    }
}
