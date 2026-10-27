<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Diadoc;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\Contracts\Edo\EdoProviderInterface;
use App\Contracts\Edo\EdoReadProviderInterface;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Exceptions\EdoException;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\DTO\EdoSyncResult;
use App\Models\EdoDocument;
use Illuminate\Support\Facades\Storage;

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

    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        // TODO: сформировать XML УПД через Upd503Builder и вернуть DTO
        throw EdoException::operationNotSupported('createStructuredDocument', 'diadoc');
    }

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        throw EdoException::operationNotSupported('addAttachment', 'diadoc');
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        throw EdoException::operationNotSupported('signDocument', 'diadoc');
    }

    public function sendDocument(string $externalId): void
    {
        $document = EdoDocument::query()
            ->with(['organization', 'counterparty', 'payloadAttachment', 'signedAttachment'])
            ->where('provider', EdoProviderEnum::Diadoc->value)
            ->where('external_id', $externalId)
            ->first();

        if ($document === null) {
            throw EdoException::remoteError(
                'diadoc',
                'DOCUMENT_NOT_FOUND',
                "Документ с external_id={$externalId} не найден в MSTO",
            );
        }

        $attachment = $document->payloadAttachment;
        if ($attachment === null || $attachment->object_key === '') {
            throw EdoException::remoteError('diadoc', 'PAYLOAD_MISSING', 'У документа нет XML');
        }

        $xmlBinary = Storage::disk($attachment->disk)->get($attachment->object_key);
        if ($xmlBinary === null || $xmlBinary === '') {
            throw EdoException::remoteError('diadoc', 'PAYLOAD_EMPTY', 'XML документа пуст');
        }

        $signedAttachment = $document->signedAttachment;
        if ($signedAttachment === null || $signedAttachment->object_key === '') {
            throw EdoException::remoteError('diadoc', 'SIGNATURE_MISSING', 'У документа нет подписи');
        }

        $signatureBinary = Storage::disk($signedAttachment->disk)->get($signedAttachment->object_key);
        if ($signatureBinary === null || $signatureBinary === '') {
            throw EdoException::remoteError('diadoc', 'SIGNATURE_EMPTY', 'Подпись пуста');
        }

        $fromBoxId = $document->organization->edo_client_id;
        $toBoxId = $document->counterparty?->edo_client_id;

        if (!$fromBoxId || !$toBoxId) {
            throw EdoException::remoteError(
                'diadoc',
                'BOX_ID_MISSING',
                'Не заполнен edo_client_id (BoxId) у отправителя или получателя',
            );
        }

        $typeNamedId = $this->mapTypeNamedId($document->document_type);
        $function = $this->mapFunction($document->document_type);
        $version = $this->mapVersion($document->document_type);

        $payload = [
            'FromBoxId' => $fromBoxId,
            'ToBoxId' => $toBoxId,
            'DocumentAttachments' => [
                [
                    'SignedContent' => [
                        'Content' => base64_encode($xmlBinary),
                        'Signature' => base64_encode($signatureBinary),
                    ],
                    'TypeNamedId' => $typeNamedId,
                    'Function' => $function,
                    'Version' => $version,
                    'NeedRecipientSignature' => true,
                ],
            ],
        ];

        $response = $this->client->post('/V3/PostMessage', $payload);

        $messageId = $response['MessageId'] ?? null;
        $entityId = $response['Entities'][0]['EntityId'] ?? null;

        if ($messageId === null) {
            throw EdoException::remoteError('diadoc', 'NO_MESSAGE_ID', 'Диадок не вернул MessageId');
        }

        $document->update([
            'external_uuid' => $messageId,
            'status' => EdoDocumentStatus::Sended,
            'sent_at' => now(),
            'meta' => array_merge($document->meta ?? [], [
                'diadoc_entity_id' => $entityId,
                'diadoc_message_id' => $messageId,
            ]),
        ]);
    }

    private function mapTypeNamedId(EdoDocumentType $type): string
    {
        return match ($type) {
            EdoDocumentType::UpdSf,
            EdoDocumentType::UpdSfDop,
            EdoDocumentType::UpdDop => 'UniversalTransferDocument',
            EdoDocumentType::UcdCsf => 'UniversalCorrectionDocument',
            EdoDocumentType::Invoice => 'Invoice',
            default => throw EdoException::operationNotSupported('mapTypeNamedId(' . $type->value . ')', 'diadoc'),
        };
    }

    private function mapFunction(EdoDocumentType $type): string
    {
        return match ($type) {
            EdoDocumentType::UpdSf => 'СЧФ',
            EdoDocumentType::UpdSfDop => 'СЧФДОП',
            EdoDocumentType::UpdDop => 'ДОП',
            EdoDocumentType::UcdCsf => 'КСЧФ',
            EdoDocumentType::Invoice => 'СЧФ',
            default => throw EdoException::operationNotSupported('mapFunction(' . $type->value . ')', 'diadoc'),
        };
    }

    private function mapVersion(EdoDocumentType $type): string
    {
        return match ($type) {
            EdoDocumentType::UpdSf,
            EdoDocumentType::UpdSfDop,
            EdoDocumentType::UpdDop => 'utd970_05_03_01',
            EdoDocumentType::UcdCsf => 'ucd970_05_03_01',
            EdoDocumentType::Invoice => 'invoice_05_03_01',
            default => throw EdoException::operationNotSupported('mapVersion(' . $type->value . ')', 'diadoc'),
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
}
