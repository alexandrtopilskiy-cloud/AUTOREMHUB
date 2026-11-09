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
    private const PDF_PORTRAIT_ORIENTATION = 'portrait';
    private const PDF_LANDSCAPE_ORIENTATION = 'landscape';
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


        $pdf = Pdf::loadView('pdf.invoice', $data)
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
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
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE);

        return $pdf->output();
    }

    /**
     * Универсальный метод для генерации простых документов (Доверенность, Отчет и т.д.)
     */
    public function generateSimpleDocument(string $title, array $data): string
    {
        $pdf = Pdf::loadView('pdf.simple', array_merge($data, ['title' => $title]))
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
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
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
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
            ->setPaper(static::PDF_PAPER_NAME,static::PDF_PORTRAIT_ORIENTATION)
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

    public function generatePaymentReceipt(RepairRequest $request, Invoice $invoice): string
    {
        $data = [
            'request' => $request,
            'invoice' => $invoice,
            'customer' => $request->customerOrganization,
            'uk' => $request->ukOrganization ?? $request->tenant,
            'date' => now()->format('d.m.Y'),
        ];

        return Pdf::loadView('pdf.payment_receipt', $data)
            ->setPaper(static::PDF_PAPER_NAME, static::PDF_PORTRAIT_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE)
            ->output();
    }

    public function generateUpd(RepairRequest $request, WorkOrder $workOrder, Organization $sender, Organization $receiver): string
    {
        // 1. Подготавливаем товары/работы
        $items = [];
        foreach ($workOrder->items as $item) {
            $items[] = [
                'code'      => $item->code ?? '',
                'name'      => $item->name,
                'unit_code' => $item->unit_code ?? '796',
                'unit_name' => $item->unit_name ?? 'шт',
                'quantity'  => (float) $item->quantity,
                'price'     => number_format((float) $item->price, 2, '.', ''),
                'sum'       => number_format((float) $item->total, 2, '.', ''),
                'vat_rate'  => 'без НДС',
                'vat_sum'   => '0.00',
            ];
        }

        // 2. Готовим дату
        $date = $request->created_at->format('d.m.Y');

        // 3. Формируем данные
        $data = [
            'status'   => '1', // <-- МЕНЯЕМ СТАТУС НА 1 (СЧФ+ДОП)
            'document' => (object) [
                'number' => $request->number,
                'date'   => $date,
            ],
            'sender'   => [
                'name'          => $sender->name,
                'inn'           => $sender->inn,
                'kpp'           => $sender->kpp,
                'address'       => $sender->legal_address,
                'director_name' => $sender->director_name ?? 'Иванов И.И.',
            ],
            'receiver' => [
                'name'    => $receiver->name,
                'inn'     => $receiver->inn,
                'kpp'     => $receiver->kpp,
                'address' => $receiver->legal_address,
            ],
            'items'    => $items,
            'totalSum' => number_format((float) $workOrder->total, 2, '.', ''),
            'vatSum'   => '0.00',
        ];

        return Pdf::loadView('pdf.upd', $data)
            ->setPaper('a4', static::PDF_LANDSCAPE_ORIENTATION)
            ->setOption(static::PDF_OPTION_ATTRIBUTE_NAME, static::PDF_OPTION_FONT_VALUE)
            ->output();
    }
}
