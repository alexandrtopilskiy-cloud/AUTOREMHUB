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
use App\Models\Organization;
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
        // TODO: сформировать XML УПД через Upd503Builder и вернуть DTO
        throw EdoException::operationNotSupported('createStructuredDocument', EdoProviderEnum::Diadoc);
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
            ->where('external_id', $externalId)
            ->firstOrFail();

        $attachment = $document->payloadAttachment;
        if (!$attachment || !$attachment->object_key) {
            throw EdoException::remoteError(
                provider: EdoProviderEnum::Diadoc->value,
                code: 'PAYLOAD_MISSING',
                message: 'У документа нет файла (PDF)'
            );
        }

        $pdfBinary = Storage::disk($attachment->disk)->get($attachment->object_key);
        if (!$pdfBinary) {
            throw EdoException::remoteError(
                provider: EdoProviderEnum::Diadoc->value,
                code: 'PAYLOAD_EMPTY',
                message: 'Файл документа пуст'
            );
        }

        // 1. Получаем токен
        $accessToken = $this->client->getAccessToken($document->organization);

        // 2. BoxId
        $fromBoxId = $document->organization->edo_client_id;
        $toBoxId = $document->counterparty?->edo_client_id;

        if (!$fromBoxId || !$toBoxId) {
            throw EdoException::remoteError(
                provider: EdoProviderEnum::Diadoc->value,
                code: 'BOX_ID_MISSING',
                message: 'Не заполнен edo_client_id (BoxId) у отправителя или получателя'
            );
        }

        // 3. Подпись (заглушка для тестов)
        // В реальности здесь должен быть CMS SignedData в DER, закодированный в base64
        $signatureBinary = 'mock-signature';

        // 4. Формируем запрос
        $payload = [
            'FromBoxId' => $fromBoxId,
            'ToBoxId' => $toBoxId,
            'DocumentAttachments' => [
                [
                    'SignedContent' => [
                        'Content' => base64_encode($pdfBinary),
                        'Signature' => base64_encode($signatureBinary),
                        'SignWithTestSignature' => true, // Для staging
                    ],
                    'TypeNamedId' => 'nonformalized',
                    'Function' => 'default',
                    'Version' => '1.0',
                    'NeedRecipientSignature' => true,
                    'Comment' => "Документ по заявке {$document->number}",
                ],
            ],
        ];

        // 5. Отправляем
        $response = $this->client->post('/V3/PostMessage', $payload, $accessToken);

        $messageId = $response['MessageId'] ?? null;
        $entityId = $response['Entities'][0]['EntityId'] ?? null;

        if (!$messageId) {
            throw EdoException::remoteError(
                EdoProviderEnum::Diadoc->value,
                'NO_MESSAGE_ID',
                'Диадок не вернул MessageId'
            );
        }

        // 6. Обновляем документ
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
            default => throw EdoException::operationNotSupported('mapTypeNamedId(' . $type->value . ')', EdoProviderEnum::Diadoc),
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
            default => throw EdoException::operationNotSupported('mapFunction(' . $type->value . ')', EdoProviderEnum::Diadoc),
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
            default => throw EdoException::operationNotSupported('mapVersion(' . $type->value . ')', EdoProviderEnum::Diadoc),
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
