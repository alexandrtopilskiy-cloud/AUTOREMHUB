<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\RequestPart;
use App\Models\RequestWork;
use App\Models\User;

/**
 * Агентская скидка СТО в пользу УК.
 *
 * Заказчик оплачивает полную стоимость строки (100 ₽); СТО предоставляет
 * скидку за приведённого клиента (по умолчанию 10 %), поэтому СТО получает
 * 90 ₽, а 10 ₽ — агентское вознаграждение УК. Процент фиксируется в каждой
 * строке работ и запчастей из реквизитов СТО и в заявке не редактируется.
 * Заказчику эти данные не показываются.
 */
final class AgentDiscount
{
    private const MONEY_SCALE = 2;

    public static function visibleTo(UserRole $role): bool
    {
        return $role !== UserRole::Customer;
    }

    /**
     * @return array{agentDiscountPercent: float|null, agentDiscountAmount: float|null, stationAmount: float|null}
     */
    public static function workFields(RequestWork $work): array
    {
        return self::fields($work->agent_discount_percent, self::workAmount($work));
    }

    /**
     * @return array{agentDiscountPercent: float|null, agentDiscountAmount: float|null, stationAmount: float|null}
     */
    public static function partFields(RequestPart $part): array
    {
        return self::fields($part->agent_discount_percent, self::partAmount($part));
    }

    /**
     * @return array{percent: float|null, amount: float, stationAmount: float, total: float}
     */
    public static function summary(RepairRequest $request): array
    {
        $total = '0.00';
        $discount = '0.00';
        $managerParts = '0.00';
        $percents = [];
        // «ЗЧ от УК»: эти ЗЧ реализует УК, СТО за них не получает оплату.
        $awaitsManagerParts = $request->awaitsManagerParts();
        foreach ($request->parts as $part) {
            if ($part->from_manager || $awaitsManagerParts) {
                $managerParts = bcadd($managerParts, self::partAmount($part), self::MONEY_SCALE);
            }
        }

        $lines = [
            ...$request->workLines->map(static fn (RequestWork $work): array => [
                $work->agent_discount_percent,
                self::workAmount($work),
            ])->all(),
            ...$request->parts->map(static fn (RequestPart $part): array => [
                $part->agent_discount_percent,
                self::partAmount($part),
            ])->all(),
        ];

        foreach ($lines as [$percent, $amount]) {
            if ($amount === null) {
                continue;
            }

            $total = bcadd($total, $amount, self::MONEY_SCALE);

            if ($percent !== null) {
                $percents[(string) (float) $percent] = true;
                $discount = bcadd($discount, self::discount($amount, (string) $percent), self::MONEY_SCALE);
            }
        }

        $stationPercent = $request->stationRecord?->agent_discount_percent;

        return [
            // Единый процент по всем строкам, иначе — процент из реквизитов СТО.
            'percent'       => count($percents) === 1
                ? (float) array_key_first($percents)
                : ($stationPercent !== null ? (float) $stationPercent : null),
            'amount'        => (float) $discount,
            'stationAmount' => (float) bcsub(bcsub($total, $discount, self::MONEY_SCALE), $managerParts, self::MONEY_SCALE),
            'total'         => (float) $total,
        ];
    }

    /**
     * @return array{agentDiscountPercent: float|null, agentDiscountAmount: float|null, stationAmount: float|null}
     */
    private static function fields(mixed $percent, ?string $amount): array
    {
        if ($percent === null || $amount === null) {
            return [
                'agentDiscountPercent' => $percent !== null ? (float) $percent : null,
                'agentDiscountAmount'  => null,
                'stationAmount'        => null,
            ];
        }

        $discount = self::discount($amount, (string) $percent);

        return [
            'agentDiscountPercent' => (float) $percent,
            'agentDiscountAmount'  => (float) $discount,
            'stationAmount'        => (float) bcsub($amount, $discount, self::MONEY_SCALE),
        ];
    }

    private static function discount(string $amount, string $percent): string
    {
        return bcadd(bcdiv(bcmul($amount, $percent, 6), '100', 6), '0.005', self::MONEY_SCALE);
    }

    private static function workAmount(RequestWork $work): ?string
    {
        if ($work->line_amount !== null) {
            return (string) $work->line_amount;
        }

        return $work->price !== null
            ? bcadd(bcmul((string) $work->quantity, (string) $work->price, 5), '0.005', self::MONEY_SCALE)
            : null;
    }

    private static function partAmount(RequestPart $part): string
    {
        $quantity = bcsub((string) $part->quantity, (string) $part->returned_quantity, 3);

        return bcadd(bcmul($quantity, (string) $part->price, 5), '0.005', self::MONEY_SCALE);
    }
}
