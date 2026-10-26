<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
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
    private const PDF_PAPER_ORIENTATION = 'portrait';
    /**
     * Генерирует PDF счёта.
     * Возвращает бинарное содержимое файла (string).
     */
    public function generateInvoice(RepairRequest $request, Invoice $invoice): string
    {
        $data = [
            'request' => $request,
            'invoice' => $invoice,
            'workOrder' => $request->workOrder,
            'customer' => $request->customerOrganization,
            'uk' => $request->ukOrganization ?? $request->tenant, // Или как вы получаете УК
            'date' => now()->format('d.m.Y'),
        ];


        $pdf = Pdf::loadView('pdf.act', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Генерирует PDF Акта выполненных работ (УПД).
     */
    public function generateAct(RepairRequest $request, WorkOrder $workOrder): string
    {
        $data = [
            'request' => $request,
            'workOrder' => $workOrder,
            'customer' => $request->customerOrganization,
            'station' => $request->stationRecord?->organization,
            'date' => now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.act', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Универсальный метод для генерации простых документов (Доверенность, Отчет и т.д.)
     */
    public function generateSimpleDocument(string $title, array $data): string
    {
        $pdf = Pdf::loadView('pdf.simple', array_merge($data, ['title' => $title]))
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Предварительный заказ-наряд - УК -> Заказчик
     */
    public function generateEstimate(RepairRequest $request): string
    {
        $data = [
            'request' => $request,
            'works' => $request->workLines()->get(),
            'parts' => $request->parts()->get(),
            'customer' => $request->customerOrganization,
            'uk' => $request->ukOrganization ?? $request->tenant,
            'total' => app(RepairRequestCalculator::class)->total($request),
            'date' => now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.estimate', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Акт приема-передачи ТС - СТО -> УК
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
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Доверенность - Заказчик -> СТО
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
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
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
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
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
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PAPER_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }
}
