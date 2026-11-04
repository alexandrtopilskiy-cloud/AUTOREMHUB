<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Models\WorkOrder;
use App\Services\Edo\Builders\WorkOrderActBuilder;
use App\Traits\SendsEdoDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EdoActService
{
    use SendsEdoDocuments;

    public function __construct(
        private readonly WorkOrderActBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function sendAct(RepairRequest $request, WorkOrder $workOrder): EdoDocument
    {
        return DB::transaction(function () use ($request, $workOrder) {
            $sender = $request->stationRecord?->organization;
            $receiver = $request->ukOrganization;

            if (!$sender) {
                throw new \RuntimeException("У заявки {$request->number} не назначена СТО (отправитель)");
            }
            if (!$receiver) {
                throw new \RuntimeException("У заявки {$request->number} не найдена УК (получатель)");
            }

            // Провайдер для кабинета СТО
            $provider = $this->dispatcher->forOrganization($sender);

            $document = $request->edoDocuments()->firstOrCreate(
                ['document_type' => EdoDocumentType::ActWorks->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $sender->id,
                    'counterparty_id'   => $receiver->id,
                    'provider'          => $sender->edo_provider ?? 'diadoc',
                    'number'            => $request->numberWithPrefix('AVR'),
                    'document_date'     => now()->toDateString(),
                    'status'            => EdoDocumentStatus::Draft,
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            // 1. Билдер готовит DTO (пока с PDF)
            $structuredRequest = $this->builder->build($request, $workOrder, $document, $sender, $receiver);
            Log::info('BUILD XML DOCUMENT');

            // 2. Провайдер создает запись и сохраняет файл (возвращает DTO)
            $dto = $provider->createStructuredDocument($structuredRequest);

            // 3. Провайдер отправляет документ
            $provider->sendDocument($dto->externalId);
            Log::info('SENT DOCUMENT');

            return $document->refresh();
        });
    }
}
