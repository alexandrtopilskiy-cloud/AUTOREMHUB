<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Diadoc;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\Contracts\Edo\EdoProviderInterface;
use App\Contracts\Edo\EdoReadProviderInterface;
use App\Enums\AttachmentPurpose;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Exceptions\EdoException;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\DTO\EdoSyncResult;
use App\Models\Attachment;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

final class DiadocProvider implements
    EdoProviderInterface,
    EdoOutboundProviderInterface,
    EdoReadProviderInterface
{
    public function __construct(
        private readonly DiadocClient $client,
        private readonly DiadocMapper $mapper,
    ) {}

    public function provider(): EdoProviderEnum
    {
        return EdoProviderEnum::Diadoc;
    }

    /**
     * Привязка организации к Диадоку.
     */
    public function connectOrganization(Organization $org, string $refreshToken, string $boxId): void
    {
        // Сохраняем BoxId (он нужен для отправки документов)
        $org->update(['edo_client_id' => $boxId]);

        // Вызываем клиент для проверки токена и сохранения
        $this->client->connectOrganization($org, $refreshToken);
    }

    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        $fileName = $request->meta['file_name'] ?? 'document.xml';
        if (!str_ends_with($fileName, '.xml') && !str_ends_with($fileName, '.pdf')) {
            throw EdoException::remoteError(EdoProviderEnum::Diadoc->value, 'NOT_XML', 'Ожидается XML-файл');
        }

        $document = EdoDocument::create([
            'tenant_id'         => $request->meta['tenant_id'],
            'organization_id'   => $request->meta['sender_id'],
            'counterparty_id'   => $request->meta['receiver_id'],
            'document_type'     => $request->type->value,
            'provider'          => EdoProviderEnum::Diadoc->value,
            'number'            => $request->number,
            'document_date'     => $request->date->format('Y-m-d'),
            'status'            => EdoDocumentStatus::Draft,
            'source_type'       => $request->meta['source_type'] ?? null,
            'source_id'         => $request->meta['source_id'] ?? null,
        ]);

        $fileContent = base64_decode($request->meta['file_content']);

        // 👇 В мок-режиме генерируем PDF и ставим Sended
        if (config('edo.mock')) {
            // 1. Сохраняем XML
            $xmlKey = "edo/{$document->uuid}.xml";
            Storage::disk('local')->put($xmlKey, $fileContent);

            // 2. Генерируем PDF (из meta или из XML)
            if (!empty($request->meta['pdf_content'])) {
                $pdfContent = base64_decode($request->meta['pdf_content']);
            } else {
                $pdfContent = $this->convertXmlToPdf($fileContent, $document->number);
            }

            $pdfKey = "edo/{$document->uuid}.pdf";
            Storage::disk('local')->put($pdfKey, $pdfContent);

            // 3. Создаем Attachment
            $requestPeriodId = $this->resolveRequestPeriodId($request->meta['repair_request_id'] ?? null);

            $attachment = Attachment::create([
                'uuid'                     => Str::uuid(),
                'repair_request_id'        => $request->meta['repair_request_id'] ?? null,
                'uploaded_by'              => auth()->id() ?? 1,
                'request_status_period_id' => $requestPeriodId,
                'disk'                     => 'local',
                'object_key'               => $pdfKey,
                'original_name'            => str_replace('.xml', '.pdf', $fileName),
                'mime_type'                => 'application/pdf',
                'size'                     => strlen($pdfContent),
                'sha256'                   => hash('sha256', $pdfContent),
                'purpose'                  => AttachmentPurpose::EdoPayload,
            ]);

            // 4. Обновляем документ
            $document->update([
                'status' => EdoDocumentStatus::Sended,
                'sent_at' => now(),
                'payload_attachment_id' => $attachment->id,
                'meta' => array_merge($document->meta ?? [], [
                    'local_file' => $pdfKey,
                    'xml_content' => base64_encode($fileContent),
                ]),
            ]);

            return new EdoDocumentDto(
                externalId: $document->uuid,
                externalUuid: 'mock-' . Str::uuid(),
                type: $request->type,
                status: EdoDocumentStatus::Sended,
                number: $document->number,
                date: new \DateTimeImmutable($document->document_date->format('Y-m-d')),
                senderName: null,
                receiverName: null,
                content: null,
                raw: [],
            );
        }

        // 🚀 РЕАЛЬНЫЙ РЕЖИМ (XML)
        $xmlKey = "edo/{$document->uuid}.xml";
        Storage::disk('local')->put($xmlKey, $fileContent);

        $requestPeriodId = $this->resolveRequestPeriodId($request->meta['repair_request_id'] ?? null);

        $attachment = Attachment::create([
            'uuid'                     => Str::uuid(),
            'repair_request_id'        => $request->meta['repair_request_id'] ?? null,
            'uploaded_by'              => auth()->id() ?? 1,
            'request_status_period_id' => $requestPeriodId,
            'disk'                     => 'local',
            'object_key'               => $xmlKey,
            'original_name'            => $fileName,
            'mime_type'                => 'application/xml',
            'size'                     => strlen($fileContent),
            'sha256'                   => hash('sha256', $fileContent),
            'purpose'                  => AttachmentPurpose::EdoPayload,
        ]);

        $document->update([
            'payload_attachment_id' => $attachment->id,
            'meta' => array_merge($document->meta ?? [], ['local_file' => $xmlKey]),
        ]);

        return new EdoDocumentDto(
            externalId: $document->uuid,
            externalUuid: null,
            type: $request->type,
            status: EdoDocumentStatus::Draft,
            number: $document->number,
            date: new \DateTimeImmutable($document->document_date->format('Y-m-d')),
            senderName: null,
            receiverName: null,
            content: null,
            raw: [],
        );
    }

    private function resolveRequestPeriodId(?int $repairRequestId): ?int
    {
        if (!$repairRequestId) {
            return null;
        }

        $repairRequest = RepairRequest::find($repairRequestId);
        $periodId = $repairRequest?->periods()->whereNull('exited_at')->first()?->id;

        if (!$periodId) {
            $periodId = RequestStatusPeriod::where('repair_request_id', $repairRequestId)
            ->orderByDesc('id')
                ->first()?->id;
        }

        return $periodId;
    }

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        throw EdoException::operationNotSupported('addAttachment', EdoProviderEnum::Diadoc);
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        throw EdoException::operationNotSupported('signDocument', EdoProviderEnum::Diadoc);
    }

    public function sendDocument(string $externalId): void
    {
        $document = EdoDocument::query()
            ->with(['organization', 'counterparty', 'payloadAttachment'])
            ->where('provider', EdoProviderEnum::Diadoc->value)
            ->where('uuid', $externalId)
            ->firstOrFail();

        $attachment = $document->payloadAttachment;
        if (!$attachment || !$attachment->object_key) {
            throw EdoException::remoteError(EdoProviderEnum::Diadoc->value, 'PAYLOAD_MISSING', 'У документа нет XML-файла');
        }

        $xmlBinary = Storage::disk($attachment->disk)->get($attachment->object_key);
        if (!$xmlBinary) {
            throw EdoException::remoteError(EdoProviderEnum::Diadoc->value, 'PAYLOAD_EMPTY', 'Файл пуст');
        }

        $accessToken = $this->client->getAccessToken($document->organization);

        // 2. BoxId
        $fromBoxId = $document->organization->edo_client_id;
        $toBoxId = $document->counterparty?->edo_client_id;

        if (!$fromBoxId || !$toBoxId) {
            Log::channel('edo')->error('BOX_ID_MISSING', [
                'body' => 'Не заполнен edo_client_id (BoxId) у отправителя или получателя'
            ]);
            throw EdoException::remoteError(
                provider: EdoProviderEnum::Diadoc->value,
                code: 'BOX_ID_MISSING',
                message: 'Не заполнен edo_client_id (BoxId) у отправителя или получателя'
            );
        }

        // 👇 КЛЮЧЕВОЕ: получаем TFV для XML
        [$typeNamedId, $function, $version] = $this->mapDocumentMetadata($document->document_type);

        // В тестовой среде — заглушка. В проде — реальная КЭП (CMS SignedData).
        $signatureBinary = 'mock-signature';

        // 4. Формируем запрос
        $payload = [
            'FromBoxId' => $fromBoxId,
            'ToBoxId' => $toBoxId,
            'DocumentAttachments' => [
                [
                    'SignedContent' => [
                        'Content' => base64_encode($xmlBinary),
                        'Signature' => base64_encode($signatureBinary),
                        'SignWithTestSignature' => true,
                    ],
                    'TypeNamedId' => $typeNamedId,
                    'Function' => $function,
                    'Version' => $version,
                    'NeedRecipientSignature' => true,
                    'Comment' => "Документ по заявке {$document->number}",
                ],
            ],
        ];

        // 5. Отправляем
        Log::channel('edo')->info('Sending to Diadoc', [
            'url' => config('edo.providers.diadoc.base_url') . '/V3/PostMessage', // 👈 Проверяем URL
            'fromBoxId' => $fromBoxId,
            'toBoxId' => $toBoxId,
            'typeNamedId' => $typeNamedId,
            'function' => $function,
            'version' => $version,
            'payload' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->client->post('/V3/PostMessage', $payload, $accessToken);

        Log::channel('edo')->info('Diadoc response', [
            'status' => $response['status'] ?? 'unknown', // если decode вернул массив
            'messageId' => $response['MessageId'] ?? null,
        ]);

        $messageId = $response['MessageId'] ?? null;
        if (!$messageId) {
            throw EdoException::remoteError(
                provider: EdoProviderEnum::Diadoc->value,
                code: 'NO_MESSAGE_ID',
                message: 'Диадок не вернул MessageId',
            );
        }

        // 6. Обновляем документ
        $document->update([
            'external_uuid' => $messageId,
            'status' => EdoDocumentStatus::Sended,
            'sent_at' => now(),
            'meta' => array_merge($document->meta ?? [], ['diadoc_message_id' => $messageId]),
        ]);
    }

    private function mapDocumentMetadata(EdoDocumentType $type): array
    {
        return match ($type) {
            EdoDocumentType::UpdSf, EdoDocumentType::UpdSfDop => ['UniversalTransferDocument', 'СЧФДОП', 'utd970_05_03_01'],
            EdoDocumentType::UpdDop => ['UniversalTransferDocument', 'ДОП', 'utd970_05_03_01'],
            EdoDocumentType::Invoice => ['XmlProformaInvoice', '0', 'proformainvoice29_05_01_01'], // Счёт на оплату
            EdoDocumentType::ActWorks, EdoDocumentType::NonFormalized => ['nonformalized', 'default', '1.0'], // Заказ-наряд / Акт
            default => throw EdoException::operationNotSupported('mapDocumentMetadata(' . $type->value . ')', EdoProviderEnum::Diadoc->value),
        };
    }

    public function getDocument(string $externalId): EdoDocumentDto
    {
        // GET /V3/GetDocument?documentId={id}
        $response = $this->client->get('/V3/GetDocument', [
            'documentId' => $externalId,
        ]);

        return $this->mapper->toDocument($response);
    }

    public function getDocumentContent(string $externalId): EdoFile
    {
        // GET /V3/GetDocumentContent?documentId={id}
        $raw = $this->client->getRaw('/V3/GetDocumentContent', [
            'documentId' => $externalId,
        ]);

        return EdoFile::fromBinary(
            filename: "document-{$externalId}.xml",
            mimeType: 'text/xml',
            binary: $raw,
        );
    }

    public function downloadPdf(string $externalId): EdoFile
    {
        // GET /V3/GetDocumentPdf?documentId={id}
        $raw = $this->client->getRaw('/V3/GetDocumentPdf', [
            'documentId' => $externalId,
        ]);

        return EdoFile::fromBinary(
            filename: "document-{$externalId}.pdf",
            mimeType: 'application/pdf',
            binary: $raw,
        );
    }

    public function pullEvents(int $fromEventId, int $count = 100): EdoSyncResult
    {
        // GET /V3/GetEventList?afterIndexKey={fromEventId}&limit={count}
        $response = $this->client->get('/V3/GetEventList', [
            'afterIndexKey' => (string) $fromEventId,
            'limit' => $count,
        ]);

        $events = $response['Events'] ?? [];
        $lastEventId = $fromEventId;

        foreach ($events as $event) {
            $indexKey = (int) ($event['IndexKey'] ?? 0);
            if ($indexKey > $lastEventId) {
                $lastEventId = $indexKey;
            }
        }

        return new EdoSyncResult(
            events: $events,
            lastEventId: $lastEventId,
            hasMore: count($events) >= $count,
        );
    }

    public function getDocumentList(array $filters = []): array
    {
        $response = $this->client->get('/V3/GetDocuments', [
            'boxId' => $filters['boxId'] ?? '',
            'filterCategory' => $filters['category'] ?? 'Any',
            'timestampFrom' => $filters['from'] ?? now()->subMonth()->toIso8601String(),
            'timestampTo' => $filters['to'] ?? now()->toIso8601String(),
        ]);

        $documents = $response['Documents'] ?? [];

        return array_map(
            fn (array $row) => $this->mapper->toDocument($row),
            $documents,
        );
    }

    public function getClient(): DiadocClient
    {
        return $this->client;
    }
}
