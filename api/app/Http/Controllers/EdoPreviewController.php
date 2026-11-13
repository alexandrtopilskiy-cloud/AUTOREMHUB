<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttachmentMime;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\DocumentGeneratorService;
use App\Services\Edo\Builders\InvoiceBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class EdoPreviewController
{
    public function __construct(
        private readonly DocumentGeneratorService $generator,
        private readonly InvoiceBuilder $invoiceBuilder,
    ) {}

    public function workOrder(string $number): Response
    {
        $repairRequest = RepairRequest::query()
            ->where('number', $number)
            ->firstOrFail();

        // 1. Получаем организации
        $stationOrg = $repairRequest->stationRecord?->organization;
        $ukOrg = $repairRequest->ukOrganization;

        if (!$stationOrg) {
            abort(400, 'У заявки нет привязанной СТО');
        }
        if (!$ukOrg) {
            abort(400, 'У заявки нет привязанной УК');
        }

        // 2. Создаем/находим документ для номера и даты
        $document = EdoDocument::query()->firstOrCreate(
            [
                'source_type' => RepairRequest::class,
                'source_id' => $repairRequest->id,
                'document_type' => 'work_order',
            ],
            [
                'uuid' => Str::uuid(),
                'tenant_id' => $repairRequest->tenant_id,
                'organization_id' => $stationOrg->id,
                'provider' => 'sbis',
                'document_date' => now()->toDateString(),
                'number' => $repairRequest->number . '-ЗН-СТО',
                'status' => 'draft',
            ]
        );

        // 3. Вызываем текущий метод (с текущими аргументами)
        $pdfContent = $this->generator->generateWorkOrder(
            $repairRequest,
            $repairRequest->workOrder,
            $stationOrg,
            $ukOrg
        );

        // 4. Отдаем в браузер
        return response($pdfContent, 200, [
            'Content-Type' => AttachmentMime::Pdf->value,
            'Content-Disposition' => 'inline; filename="work_order_' . $document->number . '.pdf"',
        ]);
    }

    public function invoice(string $number): Response
    {
        // 1. Находим заявку
        $repairRequest = RepairRequest::query()
            ->where('number', $number)
            ->firstOrFail();

        // 2. Получаем организации (Поставщик и Покупатель)
        $stationOrg = $repairRequest->stationRecord?->organization;
        $ukOrg = $repairRequest->ukOrganization ?? $repairRequest->tenant;

        if (!$stationOrg || !$ukOrg) {
            abort(400, 'Не найдены организации для заявки');
        }

        // 3. Создаем документ
        $document = EdoDocument::firstOrCreate(
            [
                'source_type' => RepairRequest::class,
                'source_id' => $repairRequest->id,
                'document_type' => 'invoice',
            ],
            [
                'uuid' => Str::uuid(),
                'tenant_id' => $repairRequest->tenant_id,
                'organization_id' => $stationOrg->id,
                'provider' => 'sbis',
                'document_date' => now()->toDateString(),
                'number' => $repairRequest->number . '-СЧ-СТО',
                'status' => 'draft',
            ]
        );

        // 4. Генерируем PDF
        $invoicePayload = $this->invoiceBuilder->preparePayload(
            $repairRequest,
            $repairRequest->workOrder,
            $stationOrg,
            $ukOrg
        );

        $pdfContent = $this->generator->generateInvoice(
            $invoicePayload,
            $document->number,
            $document->document_date
        );

        // 5. Отдаем PDF в браузер
        return response($pdfContent, 200, [
            'Content-Type' => AttachmentMime::Pdf->value,
            'Content-Disposition' => 'inline; filename="invoice_' . $document->number . '.pdf"',
        ]);
    }
}
