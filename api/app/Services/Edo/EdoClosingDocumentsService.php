<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\Edo\Builders\UpdBuilder;
use App\Services\Edo\Builders\WorkOrderBuilder;
use App\Services\Edo\Sender\EdoDocumentSenderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EdoClosingDocumentsService
{
    public function __construct(
        private readonly UpdBuilder $updBuilder,
        private readonly WorkOrderBuilder $workOrderBuilder,
        private readonly EdoDispatcher $dispatcher,
        private readonly EdoDocumentSenderService $sender
    ) {}

    public function generateAllClosingDocuments(RepairRequest $request, bool $validateOnly = false): void
    {
        Log::channel('edo')->info("EdoService: Старт генерации для заявки {$request->number}");

        $this->generateAndSendUpdSto($request, $validateOnly);
        $this->generateAndSendUpdManager($request, $validateOnly);
        $this->generateAndSendZNSto($request, $validateOnly);
        $this->generateAndSendZNManager($request, $validateOnly);
    }

    private function generateAndSendUpdSto(RepairRequest $req, bool $validateOnly): void
    {
        Log::channel('edo')->info("EdoService: Подготовка УПД (СТО -> УК) для заявки {$req->number}");

        // Отправитель: СТО. Получатель: УК.
        $sto = $req->stationRecord->organization;
        $uk = $req->ukOrganization;

        $document = $this->getEdoDocument($req, EdoDocumentType::UpdSfDop, '-УПД-СТО', $sto->id);
        $provider = $this->dispatcher->forOrganization($sto);

        $dto = $this->updBuilder->build($req, $document, $sto, $uk);

        $this->sender->process($dto, $provider, $validateOnly);
    }

    private function generateAndSendUpdManager(RepairRequest $req, bool $validateOnly): void
    {
        Log::channel('edo')->info("EdoService: Подготовка УПД (УК -> Заказчик) для заявки {$req->number}");

        // Отправитель: УК. Получатель: Заказчик.
        $senderOrg = $req->ukOrganization ?? $req->tenant;

        $document = $this->getEdoDocument($req, EdoDocumentType::UpdSfDop, '-УПД-УК', $senderOrg->id);
        $provider = $this->dispatcher->forOrganization($senderOrg);

        $dto = $this->updBuilder->build($req, $document);

        $this->sender->process($dto, $provider, $validateOnly);
    }

    private function generateAndSendZNSto(RepairRequest $req, bool $validateOnly): void
    {
        Log::channel('edo')->info("EdoService: Подготовка Заказ-наряда (СТО -> УК) для заявки {$req->number}");

        $senderOrg = $req->stationRecord->organization;

        $document = $this->getEdoDocument($req, EdoDocumentType::WorkOrder, '-ЗН-СТО', $senderOrg->id);
        $provider = $this->dispatcher->forOrganization($senderOrg);

        $dto = $this->workOrderBuilder->build($req, $document);

        $this->sender->process($dto, $provider, $validateOnly);
    }

    private function generateAndSendZNManager(RepairRequest $req, bool $validateOnly): void
    {
        Log::channel('edo')->info("EdoService: Подготовка Заказ-наряда (УК -> Заказчик) для заявки {$req->number}");

        $senderOrg = $req->ukOrganization ?? $req->tenant;

        $document = $this->getEdoDocument($req, EdoDocumentType::WorkOrder, '-ЗН-УК', $senderOrg->id);
        $provider = $this->dispatcher->forOrganization($senderOrg);

        $dto = $this->workOrderBuilder->build($req, $document);

        $this->sender->process($dto, $provider, $validateOnly);
    }

    private function getEdoDocument(
        RepairRequest $req,
        EdoDocumentType $type,
        string $suffix,
        int $organizationId
    ): EdoDocument {
        return EdoDocument::firstOrCreate(
            [
                'source_type'   => RepairRequest::class,
                'source_id'     => $req->id,
                'document_type' => $type->value,
                'number'        => $req->number . $suffix, // Различаем по номеру
            ],
            [
                'uuid'             => Str::uuid(),
                'tenant_id'        => $req->tenant_id,
                'organization_id'  => $organizationId, // Отправитель
                'provider'         => config('edo.default'),
                'document_date'    => now()->toDateString(),
                'status'           => 'draft',
            ]
        );
    }
}
