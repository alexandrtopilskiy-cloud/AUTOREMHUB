<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\Edo\Builders\PowerOfAttorneyBuilder;
use Illuminate\Support\Facades\DB;

final class EdoPowerOfAttorneyService
{
    public function __construct(
        private readonly PowerOfAttorneyBuilder $builder,
        private readonly EdoManager $edoManager,
    ) {
    }

    public function send(RepairRequest $request): EdoDocument
    {
        return DB::transaction(function () use ($request) {
            $customer = $request->customerOrganization;
            $station = $request->stationRecord?->organization;

            if (!$station) {
                throw new \RuntimeException("У заявки {$request->number} не назначена СТО для отправки доверенности.");
            }

            // Определяем, от чьего имени отправляем (от Заказчика или его УК)
            $senderOrg = $customer->edo_provider ? $customer : $request->tenant;
            $provider = $this->edoManager->forOrganization($senderOrg);

            // 1. Создаем или обновляем локальную запись
            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::PowerOfAttorney->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $senderOrg->id,
                    'counterparty_id'   => $station->id,
                    'provider'          => $provider->code(), // Внимание: убедитесь, что у провайдера есть метод code()
                    'number'            => 'POA-' . $request->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            // 2. Собираем запрос
            $structuredRequest = $this->builder->build($request, $document, $customer, $station);

            // 3. Отправляем в ЭДО
            $result = $provider->sendDocument($structuredRequest);

            // 4. Обновляем статус
            $document->update([
                'status'        => 'sent',
                'external_id'   => $result->documentId ?? null,
                'sent_at'       => now(),
                'meta'          => array_merge($document->meta ?? [], [
                    'provider_response' => $result->rawResponse ?? [],
                ]),
            ]);

            return $document->refresh();
        });
    }
}
