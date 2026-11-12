<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\VehicleAcceptanceAct;
use App\Models\WorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;

final class DocumentGeneratorService
{
    private const PDF_OPTION_ATTRIBUTE_NAME = 'defaultFont';
    private const PDF_OPTION_FONT_VALUE = 'DejaVu Sans';
    private const PDF_PAPER_NAME = 'a4';
    private const PDF_PORTRAIT_ORIENTATION = 'portrait';
    private const PDF_LANDSCAPE_ORIENTATION = 'landscape';

    /**
     * Заказ-наряд - СТО -> УК (или УК -> Заказчик)
     */
    public function generateWorkOrder(
        RepairRequest $request,
        WorkOrder $workOrder,
        Organization $sender,
        Organization $receiver
    ): string {
        $data = [
            'request'    => $request,
            'workOrder'  => $workOrder,
            'sender'     => $sender,
            'receiver'   => $receiver,
            'items'      => $workOrder->items,
            'total'      => $workOrder->total,
            'date'       => now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.work_order', $data)
            ->setPaper(static::PDF_PAPER_NAME, static::PDF_PORTRAIT_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Акт приема-передачи ТС - СТО -> Водители (из доверенности)
     */
    public function generateAcceptanceAct(RepairRequest $request, VehicleAcceptanceAct $act): string
    {
        $data = [
            'request' => $request,
            'act' => $act,
            'customer' => $request->customerOrganization,
            'station' => $request->stationRecord?->organization,
            'uk' => $request->ukOrganization ?? $request->tenant,
            'vehicle' => $request->vehicleRecord,
            'date' => now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.acceptance_act', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Доверенность на право подписи акта приёмо-передачи ТС - Заказчик -> Водители
     */
    public function generatePowerOfAttorney(RepairRequest $request): string
    {
        $data = [
            'request' => $request,
            'customer' => $request->customerOrganization,
            'station' => $request->stationRecord?->organization,
            'vehicle' => $request->vehicleRecord,
            'driver' => $request->driverRecord,
            'date' => now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.power_of_attorney', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Отчет агента - УК -> Заказчик
     */
    public function generateAgentReport(RepairRequest $request): string
    {
        $data = [
            'request' => $request,
            'customer' => $request->customerOrganization,
            'uk' => $request->ukOrganization ?? $request->tenant,
            'workOrder' => $request->workOrder,
            'date' => now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.agent_report', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Агентский договор - УК -> Заказчик
     */
    public function generateAgentContract(Organization $customer, Organization $uk): string
    {
        $data = [
            'customer' => $customer,
            'uk' => $uk,
            'date' => now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.agent_contract', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }
}
