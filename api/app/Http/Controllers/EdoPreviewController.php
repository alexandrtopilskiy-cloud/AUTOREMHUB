<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\DocumentGeneratorService;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use App\Enums\AttachmentMime;

final class EdoPreviewController
{
    public function __construct(
        private readonly DocumentGeneratorService $generator
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

        // 2. Создаем/находим фиктивный документ для номера и даты
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
}
