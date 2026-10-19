<?php

declare(strict_types=1);

namespace App\Services;

final class WorkCostService
{
    private const SCALE = 2;

    /**
     * @return array{unitPrice: string, amount: string}
     */
    public function calculate(string $normHours, string $hourlyRate, string $quantity): array
    {
        $rawUnitPrice = bcmul($normHours, $hourlyRate, 9);
        $unitPrice = $this->round($rawUnitPrice);
        $amount = $this->round(bcmul($rawUnitPrice, $quantity, 9));

        return [
            'unitPrice' => $unitPrice,
            'amount' => $amount,
        ];
    }

    private function round(string $value): string
    {
        return bcadd($value, '0.005', self::SCALE);
    }
}
