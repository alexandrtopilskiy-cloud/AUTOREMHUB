<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
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
 * СТО (как и в карточке ЗР). СТО видит дату оплаты Заказчика, но не номер
 * счёта УК→Заказчик.
 */
final class ReportController
{
    public function requests(Request $request, RepairRequestCalculator $calculator): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;
        $dto = UserDto::fromUserModel($user);
        $relations = [
            'vehicleRecord',
            'stationRecord',
            'customerOrganization',
            'periods',
            'documents',
            'workLines',
            'parts',
            'reasonRecord',
        ];

        $rows = RepairRequest::forUser($dto)
            ->whereIn('stage', RepairStage::reportStageValues())
            ->with($relations)
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
                    // Передано на СТО — приёмка ТС по акту (переход в «В работе»).
                    'transferredAt' => $entered(RepairStage::RepairInProgress),
                    // Выдано с СТО — переход в «Выдан. Подготовка документов».
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
                    'customerPaidAt'  => $customerInvoice?->paid_at?->toDateString(),
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
            'stages' => array_map(
                static fn (RepairStage $stage): array => [
                    'value' => $stage->value,
                    'label' => $stage->label()
                ],
                RepairStage::reportStages()
            ),
        ]);
    }
}
