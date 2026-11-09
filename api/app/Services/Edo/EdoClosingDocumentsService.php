<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Models\Organization;
use App\Models\WorkOrder;
use App\Services\Edo\Builders\AgentReportBuilder;
use App\Services\Edo\Builders\InvoiceBuilder;
use App\Services\Edo\Builders\UpdBuilder;
use App\Services\Edo\Drivers\Diadoc\DiadocClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EdoClosingDocumentsService
{
    public function __construct(
        private readonly UpdBuilder $updBuilder,
        private readonly InvoiceBuilder $invoiceBuilder,
        private readonly AgentReportBuilder $agentReportBuilder,
        private readonly EdoDispatcher $dispatcher,
    ) {}

    public function generate(RepairRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $workOrder = $request->workOrder;
            $station = $request->stationRecord?->organization;
            $manager = $request->ukOrganization;
            $customer = $request->customerOrganization;

            if (!$workOrder || !$station || !$manager || !$customer) {
                throw new \RuntimeException("Не хватает данных для генерации закрывающих документов по заявке {$request->number}");
            }

            // 1. Пакет СТО -> УК (по сумме со скидкой)
            $this->sendUpd($request, $workOrder, $station, $manager, true);
            $this->sendInvoice($request, $station, $manager, true);

            // 2. Пакет УК -> Заказчик (по полной сумме)
            $this->sendUpd($request, $workOrder, $manager, $customer, false);
            $this->sendInvoice($request, $manager, $customer, false);

            // 3. Агентский отчет (УК -> Заказчик на сумму скидки)
            $this->sendAgentReport($request, $manager, $customer);
        });
    }

    private function sendUpd(
        RepairRequest $request,
        WorkOrder $workOrder,
        Organization $sender,
        Organization $receiver,
        bool $isStationToManager
    ): void {
        $provider = $this->dispatcher->forOrganization($sender);

        $document = $request->edoDocuments()->firstOrCreate(
            ['document_type' => EdoDocumentType::UpdSfDop->value, 'organization_id' => $sender->id],
            [
                'tenant_id' => $request->tenant_id,
                'counterparty_id' => $receiver->id,
                'provider' => $sender->edo_provider ?? 'diadoc',
                'number' => 'UPD-' . $request->number . ($isStationToManager ? '-S' : '-C'),
                'document_date' => now()->toDateString(),
                'status' => 'draft',
                'source_type' => RepairRequest::class,
                'source_id' => $request->id,
            ]
        );

        $structuredRequest = $this->updBuilder->build($request, $workOrder, $document, $sender, $receiver, $isStationToManager);

        // 👇 Валидация перед отправкой
        if (!$this->validateBeforeSend($provider, $document, $sender, $receiver, 'UniversalTransferDocument', 'СЧФДОП', 'utd970_05_03_01')) {
            // Логируем, но не отправляем (или бросаем исключение, если нужно строго)
            Log::channel('edo')->warning("UPД не прошел валидацию, отправка пропущена", ['number' => $document->number]);
            return;
        }
        Log::channel('edo')->info('TRY SENDING....');

        // 👇 Отправка (только если валидация прошла)
        $dto = $provider->createStructuredDocument($structuredRequest);
        $provider->sendDocument($dto->externalId);
    }

    private function sendInvoice(RepairRequest $request, Organization $sender, Organization $receiver, bool $isStationToManager): void
    {
        $provider = $this->dispatcher->forOrganization($sender);

        $document = $request->edoDocuments()->firstOrCreate(
            ['document_type' => EdoDocumentType::Invoice->value, 'organization_id' => $sender->id],
            [
                'tenant_id' => $request->tenant_id,
                'counterparty_id' => $receiver->id,
                'provider' => $sender->edo_provider ?? 'diadoc',
                'number' => 'INV-' . $request->number . ($isStationToManager ? '-S' : '-C'),
                'document_date' => now()->toDateString(),
                'status' => 'draft',
                'source_type' => RepairRequest::class,
                'source_id' => $request->id,
            ]
        );

        $structuredRequest = $this->invoiceBuilder->build($request, $document, $sender, $receiver, $isStationToManager);
        $dto = $provider->createStructuredDocument($structuredRequest);
        $provider->sendDocument($dto->externalId);
    }

    private function sendAgentReport(RepairRequest $request, Organization $sender, Organization $receiver): void
    {
        $provider = $this->dispatcher->forOrganization($sender);

        $document = $request->edoDocuments()->firstOrCreate(
            ['document_type' => EdoDocumentType::NonFormalized->value, 'organization_id' => $sender->id],
            [
                'tenant_id' => $request->tenant_id,
                'counterparty_id' => $receiver->id,
                'provider' => $sender->edo_provider ?? 'diadoc',
                'number' => 'REP-' . $request->number,
                'document_date' => now()->toDateString(),
                'status' => 'draft',
                'source_type' => RepairRequest::class,
                'source_id' => $request->id,
            ]
        );

        $structuredRequest = $this->agentReportBuilder->build($request, $document, $sender, $receiver);
        $dto = $provider->createStructuredDocument($structuredRequest);
        $provider->sendDocument($dto->externalId);
    }

    private function validateBeforeSend(
        $provider,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver,
        string $typeNamedId,
        string $function,
        string $version
    ): bool {
        if (config('edo.mock')) {
            return true; // В мок-режиме пропускаем
        }

        try {
            /** @var DiadocClient $client */
            $client = $provider->getClient();
            $accessToken = $client->getAccessToken($sender);

            $prototype = [
                'FromBoxId' => $sender->edo_client_id,
                'ToBoxId' => $receiver->edo_client_id,
                'DocumentPrototypes' => [
                    [
                        'TypeNamedId' => $typeNamedId,
                        'Function' => $function,
                        'Version' => $version,
                    ],
                ],
            ];
            Log::channel('edo')->info(json_encode($prototype, JSON_PRETTY_PRINT));

            $result = $client->canPostMessage($prototype, $accessToken);

            if (!empty($result['Errors'])) {
                foreach ($result['Errors'] as $error) {
                    Log::channel('edo')->error('CanPostMessage error', [
                        'document' => $document->number,
                        'error' => $error['Message'] ?? 'Unknown',
                    ]);
                }
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel('edo')->error('CanPostMessage exception', [
                'document' => $document->number,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
