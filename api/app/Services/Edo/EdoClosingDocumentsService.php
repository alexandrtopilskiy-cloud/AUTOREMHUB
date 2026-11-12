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

        $sto = $req->stationRecord->organization;
        $uk = $req->ukOrganization;

        $document = $this->getEdoDocument(
            req: $req,
            type: EdoDocumentType::UpdSfDop,
            suffix: '-УПД-СТО',
            senderId: $sto->id,
        );
        $provider = $this->dispatcher->forOrganization($sto);

        $dto = $this->updBuilder->build($req, $document, $sto, $uk);

        $this->sender->process($document, $dto, $provider, $validateOnly);
    }

    private function generateAndSendUpdManager(RepairRequest $req, bool $validateOnly): void
    {
        Log::channel('edo')->info("EdoService: Подготовка УПД (УК -> Заказчик) для заявки {$req->number}");

        $uk = $req->ukOrganization;
        $customer = $req->customerOrganization;

        $document = $this->getEdoDocument(
            req: $req,
            type: EdoDocumentType::UpdSfDop,
            suffix: '-УПД-УК',
            senderId: $uk->id,
        );
        $provider = $this->dispatcher->forOrganization($uk);

        $dto = $this->updBuilder->build($req, $document, $uk, $customer);

        $this->sender->process($document, $dto, $provider, $validateOnly);
    }

    private function generateAndSendZNSto(RepairRequest $req, bool $validateOnly): void
    {
        Log::channel('edo')->info("EdoService: Подготовка Заказ-наряда (СТО -> УК) для заявки {$req->number}");

        $sto = $req->stationRecord->organization;
        $uk = $req->ukOrganization;

        $document = $this->getEdoDocument(
            req: $req,
            type: EdoDocumentType::WorkOrder,
            suffix: '-ЗН-СТО',
            senderId: $sto->id,
        );
        $provider = $this->dispatcher->forOrganization($sto);

        $dto = $this->workOrderBuilder->build($req, $document, $sto, $uk);

        $this->sender->process($document, $dto, $provider, $validateOnly);
    }

    private function generateAndSendZNManager(RepairRequest $req, bool $validateOnly): void
    {
        Log::channel('edo')->info("EdoService: Подготовка Заказ-наряда (УК -> Заказчик) для заявки {$req->number}");

        $uk = $req->ukOrganization;
        $customer = $req->customerOrganization;

        $document = $this->getEdoDocument(
            req: $req,
            type: EdoDocumentType::WorkOrder,
            suffix: '-ЗН-УК',
            senderId: $uk->id,
        );
        $provider = $this->dispatcher->forOrganization($uk);

        $dto = $this->workOrderBuilder->build($req, $document, $uk, $customer);

        $this->sender->process($document, $dto, $provider, $validateOnly);
    }

    private function getEdoDocument(RepairRequest $req, EdoDocumentType $type, string $suffix, int $senderId): EdoDocument
    {
        return EdoDocument::firstOrCreate(
            [
                'source_type'   => RepairRequest::class,
                'source_id'     => $req->id,
                'document_type' => $type->value,
                'number'        => $req->number . $suffix,
            ],
            [
                'uuid'             => Str::uuid(),
                'tenant_id'        => $req->tenant_id,
                'organization_id'  => $senderId,
                'provider'         => config('edo.default'),
                'document_date'    => now()->toDateString(),
                'status'           => 'draft',
            ]
        );
    }
}
