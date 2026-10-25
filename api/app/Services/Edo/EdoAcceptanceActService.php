<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Models\VehicleAcceptanceAct;
use App\Services\Edo\Builders\AcceptanceActBuilder;
use Illuminate\Support\Facades\DB;

final class EdoAcceptanceActService
{
    public function __construct(
        private readonly AcceptanceActBuilder $builder,
        private readonly EdoDispatcher $dispatcher,
    ) {
    }

    public function send(RepairRequest $request, VehicleAcceptanceAct $act): EdoDocument
    {
        return DB::transaction(function () use ($request, $act) {
            $stationOrg = $request->stationRecord?->organization;
            $ukOrg = $request->tenant; // или организация УК, если есть отдельная

            if (!$stationOrg) {
                throw new \RuntimeException("У заявки {$request->number} не назначена СТО");
            }

            // Отправитель - СТО, получатель - УК
            $provider = $this->dispatcher->forOrganization($stationOrg);

            $document = $request->edoDocuments()
                ->firstOrCreate(
                ['document_type' => EdoDocumentType::AcceptanceAct->value],
                [
                    'tenant_id'         => $request->tenant_id,
                    'organization_id'   => $stationOrg->id,
                    'counterparty_id'   => $ukOrg->id,
                    'provider'          => $stationOrg->edo_provider,
                    'number'            => $act->number,
                    'document_date'     => now()->toDateString(),
                    'status'            => 'draft',
                    'source_type'       => RepairRequest::class,
                    'source_id'         => $request->id,
                ]
            );

            $structuredRequest = $this->builder->build($request, $act, $document, $stationOrg, $ukOrg);
            $result = $provider->sendDocument($structuredRequest);

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
