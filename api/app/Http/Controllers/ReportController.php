<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RepairStage;
use App\Enums\RequestDocumentType;
use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Services\RepairRequestCalculator;
use App\Support\AgentDiscount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Отчёт по ЗР, дошедшим до «Подтверждение оплаты Заказчика», «Оплата в СТО»
 * или «Заявка на ремонт закрыта». УК видит все ЗР, СТО — свои, Заказчик —
 * свои; агентская скидка Заказчику не отдаётся, наименование Заказчика —
 * СТО (как и в карточке ЗР).
 */
final class ReportController
{
    public const STAGES = [
        RepairStage::CustomerPayment,
        RepairStage::StationPayment,
        RepairStage::Closed,
    ];

    public function requests(Request $request, RepairRequestCalculator $calculator): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;

        $rows = RepairRequest::forUser($user)
            ->whereIn('stage', array_map(static fn (RepairStage $stage): int => $stage->value, self::STAGES))
            ->with(['vehicleRecord', 'stationRecord', 'customerOrganization', 'periods', 'documents', 'workLines', 'parts', 'reasonRecord'])
            ->latest('id')
            ->get()
            ->map(function (RepairRequest $item) use ($role, $calculator): array {
                $entered = fn (RepairStage $stage): ?string => $item->periods
                    ->filter(fn (RequestStatusPeriod $period): bool => $period->stage === $stage)
                    ->sortByDesc('entered_at')
                    ->first()?->entered_at?->toISOString();
                $customerInvoice = $item->document(RequestDocumentType::CustomerInvoice);
                $stationInvoice = $item->document(RequestDocumentType::StationInvoice);
                $discount = AgentDiscount::summary($item);
                $vehicle = $item->vehicleRecord;

                $row = [
                    'id'            => $item->uuid,
                    'number'        => $item->number,
                    'stage'         => $item->stage->value,
                    'status'        => $item->stage->label(),
                    'createdAt'     => $item->created_at?->toISOString(),
                    'issuedAt'      => $entered(RepairStage::Issued),
                    'closedAt'      => $entered(RepairStage::Closed),
                    'customer'      => $role === UserRole::Station ? null : ($item->customerOrganization?->name ?? ''),
                    'station'       => $item->stationRecord?->name ?? '',
                    'vehicle'       => trim(($vehicle?->make ?? '') . ' ' . ($vehicle?->model ?? '')),
                    'plate'         => $vehicle?->plate ?? '',
                    'vin'           => $vehicle?->vin ?? '',
                    'reason'        => $item->reasonRecord?->name ?? '',
                    'amount'        => (float) $calculator->total($item),
                    'customerInvoice' => $role === UserRole::Station ? null : $customerInvoice?->number,
                    'customerPaidAt'  => $role === UserRole::Station ? null : $customerInvoice?->paid_at?->toDateString(),
                ];

                if ($role !== UserRole::Customer) {
                    $row += [
                        'agentDiscountPercent' => $discount['percent'],
                        'agentDiscount'        => $discount['amount'],
                        'stationAmount'        => $discount['stationAmount'],
                        'stationInvoice'       => $stationInvoice?->number,
                        'stationPaidAt'        => $stationInvoice?->paid_at?->toDateString(),
                    ];
                }

                return $row;
            })
            ->values();

        return response()->json([
            'rows'   => $rows,
            'stages' => array_map(static fn (RepairStage $stage): array => ['value' => $stage->value, 'label' => $stage->label()], self::STAGES),
        ]);
    }
}
