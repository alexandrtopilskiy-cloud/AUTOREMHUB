<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\EdoRu;

use App\Contracts\Edo\EdoProviderInterface;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Enums\Edo\UpdFunction;
use App\Exceptions\EdoException;
use App\Models\EdoDocument;
use App\DTO\EdoAcceptTitleRequest;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoOrganizationDto;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\DTO\EdoSyncResult;
use App\DTO\EdoUcdStructuredRequest;
use App\Services\Edo\Xml\Upd503Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Провайдер ЭДО.ру (ИС «ЭДО.Поток»).
 *
 * Реализовано (этап 1–4):
 *   - findOrganization()     → GET  /contractors/search-contractors
 *   - getDocument()          → GET  /documents/document
 *   - getDocumentContent()   → GET  /documents/download-doc  (бинарный)
 *   - downloadPdf()          → GET  /documents/show-doc-pdf  (бинарный)
 *   - pullEvents()           → GET  /documents/find          (курсор по docId)
 *   - signDocument()         → POST /documents/send          (по контракту)
 *   - sendDocument()         → no-op (см. signDocument)
 *   - acceptDocument()       → POST /documents/provisional/tech + .../sign
 *   - rejectDocument()       → POST /documents/provisional/reject
 *   - createUnstructuredDocument() → POST /documents/send
 *   - addAttachment()        → no-op (в ЭДО.Поток нет прямого аналога)
 *
 * Заглушки (ожидаем XML-билдер УПД/УКД и XSD от менеджера):
 *   - getSelfOrganization()  (в доке нет прямого метода — уточняем)
 *   - createStructuredDocument()
 *   - createUcdDocument()
 *   - acceptTitle()          (POST /documents/provisional/buyer/title)
 */
final class EdoRuProvider implements EdoProviderInterface
{
    private const PROVIDER_LABEL = 'edoru';

    /** Подпись, ожидающая отправки вместе с документом. */
    private ?EdoSignature $pendingSignature = null;

    public function __construct(
        private readonly EdoRuClient $client,
        private readonly EdoRuMapper $mapper,
        private readonly Upd503Builder $updBuilder,
    ) {}

    public function provider(): EdoProviderEnum
    {
        return EdoProviderEnum::EdoRu;
    }

    /* ────────────────────────────────────────────────────────────
     | Организации
     ──────────────────────────────────────────────────────────── */

    public function getSelfOrganization(): EdoOrganizationDto
    {
        // В API v5.2 нет явного метода «получить себя».
        // Прямой эквивалент СберКорусовскому GET /v2.0/client/self отсутствует.
        // Вариант через auth-with-ds (edoOrgId из сессии) требует КЭП.
        // Уточняем у менеджера: возможно, GET /contractors/invitations для self,
        // либо GET /clients/favorite-receiver для self по ИНН.
        throw EdoException::operationNotSupported(
            'getSelfOrganization',
            self::PROVIDER_LABEL,
        );
    }

    public function findOrganization(string $inn, ?string $kpp = null): ?EdoOrganizationDto
    {
        // GET /contractors/search-contractors?query={inn|kpp|наименование}
        $query = $inn;
        if ($kpp !== null && $kpp !== '') {
            $query = $inn; // Поиск по ИНН достаточен; KPP уточним в ответе.
        }

        $payload = $this->client->get('/contractors/search-contractors', ['query' => $query]);

        $rows = (array) ($payload['result']['data'] ?? []);

        if ($rows === []) {
            return null;
        }

        // Ищем точное совпадение по ИНН и (если задан) КПП.
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ((string) ($row['inn'] ?? '') !== $inn) {
                continue;
            }

            if ($kpp !== null && $kpp !== '' && (string) ($row['kpp'] ?? '') !== $kpp) {
                continue;
            }

            return $this->mapper->toOrganization($row);
        }

        return null;
    }

    /* ────────────────────────────────────────────────────────────
     | Исходящие документы
     ──────────────────────────────────────────────────────────── */

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        // В ЭДО.Поток нет прямого аналога addAttachment.
        // Файлы идут в составе /documents/send-package (relatedDocs).
        // Пока фиксируем no-op, уточняем у менеджера.
        Log::info('EdoRu: addAttachment called but not supported', [
            'external_id' => $externalId,
            'filename'    => $file->filename,
        ]);
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        // По контракту EdoProviderInterface это отдельный шаг. Для ЭДО.Поток
        // подпись отправляется вместе с документом в POST /documents/send.
        // Реализация: находим EdoDocument по external_id, достаём XML,
        // отправляем /documents/send, сохраняем docId в external_id.
        $document = EdoDocument::query()
        ->where('provider', EdoProviderEnum::EdoRu->value)
            ->where('external_id', $externalId)
            ->first();

        if ($document === null) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'DOCUMENT_NOT_FOUND',
                "Документ с external_id={$externalId} не найден в MSTO",
            );
        }

        $attachment = $document->payloadAttachment;
        if ($attachment === null || $attachment->object_key === '') {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'PAYLOAD_MISSING',
                'У документа нет XML (payload_attachment_id пуст)',
            );
        }

        $xmlBinary = Storage::disk($attachment->disk)->get($attachment->object_key);

        if ($xmlBinary === null || $xmlBinary === '') {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'PAYLOAD_EMPTY',
                'XML документа пуст',
            );
        }

        $counterparty = $document->counterparty;
        if ($counterparty === null || $counterparty->edo_edi_code === null) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'COUNTERPARTY_NO_EDI_CODE',
                'У контрагента не заполнен edo_edi_code',
            );
        }

        $docType = $this->mapper->toDocType($document->document_type);

        $body = [
            'to'        => $counterparty->edo_edi_code,
            'docType'   => $docType,
            'content'   => base64_encode($xmlBinary),
            'signature' => $signature->content,
            'fileName'  => $attachment->original_name ?: ($document->number . '.xml'),
            'docAttrs'  => [
                'docName'   => (string) $document->document_type->label(),
                'docNumber' => (string) $document->number,
                'docDate'   => $document->document_date?->format('d.m.Y') ?? now()->format('d.m.Y'),
            ],
        ];

        $this->client->post('/documents/send', $body);
    }

    public function sendDocument(string $externalId): void
    {
        // Для ЭДО.Поток отправка выполняется в signDocument (send требует
        // подпись в одном запросе). Здесь — no-op для совместимости контракта.
    }

    /* ────────────────────────────────────────────────────────────
     | Входящие действия
     ──────────────────────────────────────────────────────────── */

    public function acceptDocument(string $externalId, EdoSignature $ticket): void
    {
        // POST /documents/provisional/tech → contentBase64 + uuid
        // POST /documents/provisional/tech/sign { uuid, signatureBase64 }
        // Для упрощения: используем tech/sign сразу с подписью.
        // Но по доке нужен uuid черновика, поэтому flow двухступенчатый.
        // TODO(после ответа менеджера): уточнить, можно ли передавать ticket
        // напрямую в /documents/provisional/tech/sign без предварительного
        // формирования (в доке нужен uuid).
        throw EdoException::operationNotSupported(
            'acceptDocument',
            self::PROVIDER_LABEL,
        );
    }

    public function rejectDocument(string $externalId, string $comment): void
    {
        // POST /documents/provisional/reject { provisionalDocParams, additionalParams }
        // → contentBase64 + uuid
        // POST /documents/provisional/rejected/sign { uuid, signatureBase64 }
        // TODO(после ответа менеджера): нужен fingerprint сертификата и
        // подпись содержимого. Пока — заглушка.
        throw EdoException::operationNotSupported(
            'rejectDocument',
            self::PROVIDER_LABEL,
        );
    }

    /* ────────────────────────────────────────────────────────────
     | Чтение
     ──────────────────────────────────────────────────────────── */

    public function getDocument(string $externalId): EdoDocumentDto
    {
        // GET /documents/document?docId={id} → result
        $payload = $this->client->get('/documents/document', ['docId' => $externalId]);

        return $this->mapper->toDocument((array) ($payload['result'] ?? []));
    }

    public function getDocumentContent(string $externalId): EdoFile
    {
        // GET /documents/download-doc?docId=&downloadType=CURRENT → бинарный
        $binary = $this->client->getRaw('/documents/download-doc', [
            'docId'        => $externalId,
            'downloadType' => 'CURRENT',
        ]);

        return EdoFile::fromBinary(
            filename: "document-{$externalId}.xml",
            mimeType: 'text/xml',
            binary:   $binary,
        );
    }

    public function downloadPdf(string $externalId): EdoFile
    {
        // GET /documents/show-doc-pdf?docId={id} → бинарный
        $binary = $this->client->getRaw('/documents/show-doc-pdf', [
            'docId' => $externalId,
        ]);

        return EdoFile::fromBinary(
            filename: "document-{$externalId}.pdf",
            mimeType: 'application/pdf',
            binary:   $binary,
        );
    }

    /* ────────────────────────────────────────────────────────────
     | События
     ──────────────────────────────────────────────────────────── */

    public function pullEvents(int $fromEventId, int $count = 100): EdoSyncResult
    {
        // У ЭДО.Поток нет отдельного events-эндпоинта.
        // Опрашиваем GET /documents/find с курсором по docId.
        // fromEventId трактуем как «последний известный docId».
        // В ответе получаем data[] и pageInfo.
        //
        // ВАЖНО: fromEventId в контракте интерпретируется как «с какого
        // eventId продолжить». У ЭДО.Поток нет eventId. Мы шлём:
        //   - без from/to: забираем последние N документов, sortDirection=desc
        //   - затем фильтруем только те, у которых docId > fromEventId.
        // Это «best effort» до уточнения у менеджера.
        $payload = $this->client->get('/documents/find', [
            'direction'     => 'in',
            'typeOfDate'    => 'STATUS_CHANGE_DATE',
            'pageIndex'     => 1,
            'pageRecords'   => max(1, min($count, 1000)),
            'pageCount'     => 1,
            'sortKey'       => 'doc_id',
            'sortDirection' => 'asc',
        ]);

        $result = $this->mapper->toSyncResult((array) ($payload['result'] ?? []));

        // Фильтруем по курсору: только документы с docId > fromEventId.
        $filtered = array_values(array_filter(
            $result->events,
            static fn (array $row): bool => (int) ($row['docId'] ?? 0) > $fromEventId,
        ));

        $lastEventId = $fromEventId;
        foreach ($filtered as $row) {
            $id = (int) ($row['docId'] ?? 0);
            if ($id > $lastEventId) {
                $lastEventId = $id;
            }
        }

        return new EdoSyncResult(events: $filtered, lastEventId: $lastEventId);
    }

    /* ────────────────────────────────────────────────────────────
     | Структурированные документы (заглушки до XML-билдера)
     ──────────────────────────────────────────────────────────── */

    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        $function = $this->resolveUpdFunction($request->type);

        $fileName = $this->buildUpdFileName(
            senderEdiCode:   $request->senderEdiCode,
            receiverEdiCode: $request->receiverEdiCode,
            date:            $request->date,
        );

        if ($request->signer === null) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'NO_SIGNER',
                'EdoStructuredRequest::signer обязателен для createStructuredDocument',
            );
        }

        $xml = $this->updBuilder->build(
            payload:  $request->payload,
            function: $function,
            number:   $request->number,
            date:     $request->date,
            fileName: $fileName,
            signer:   $request->signer,
        );

        return new EdoDocumentDto(
            externalId:   '',          // externalId получим после POST /documents/send
            externalUuid: null,
            type:         $request->type,
            status:       EdoDocumentStatus::Draft,
            number:       $request->number,
            date:         $request->date,
            senderName:   $request->payload->seller->name,
            receiverName: $request->payload->buyer->name,
            content:      EdoFile::fromBinary(
                filename: $fileName . '.xml',
                mimeType: 'text/xml',
                binary:   $xml,
            ),
            raw:          [],
        );
    }

    private function resolveUpdFunction(EdoDocumentType $type): UpdFunction
    {
        return match ($type) {
            EdoDocumentType::UpdSf    => UpdFunction::Sf,
            EdoDocumentType::UpdSfDop => UpdFunction::SfDop,
            EdoDocumentType::UpdDop   => UpdFunction::Dop,
            default => throw EdoException::operationNotSupported(
                'createStructuredDocument(' . $type->value . ')',
                self::PROVIDER_LABEL,
            ),
        };
    }

    private function buildUpdFileName(
        string $senderEdiCode,
        string $receiverEdiCode,
        \DateTimeImmutable $date,
    ): string {
        // Формат по приказу ФНС № ЕД-7-26/970@, п. 4:
        // ON_NSCHFDOPPR_<A>_<O>_<ГГГГММДД>_<N1>_<N2>_<N3>_<N4>_<N5>_<N6>_<N7>
        // Где A — получатель, O — отправитель; и N2..N6 = 0, N7 = 00.
        // УИОЭДО (префикс оператора 2PS и т.п.) подставляется после ЛК.
        return sprintf(
            'ON_NSCHFDOPPR_%s_%s_%s_%s_0_0_0_0_0_00',
            $this->shortEdiId($receiverEdiCode),
            $this->shortEdiId($senderEdiCode),
            $date->format('Ymd'),
            (string) Str::uuid(),
        );
    }

    private function shortEdiId(string $ediCode): string
    {
        // TODO(после ЛК): уточнить, нужен ли префикс оператора (2PS и т.п.).
        // Пока — только ИдУчЭДО без префикса.
        return $ediCode !== '' ? $ediCode : 'UNKNOWN';
    }

    public function createUnstructuredDocument(
        EdoDocumentType $type,
        EdoFile $content,
        string $number,
        \DateTimeImmutable $date,
        string $receiverEdiCode,
    ): EdoDocumentDto {
        // POST /documents/send — но там требуется подпись.
        // Для неформализованных документов flow другой: drafts/add + send-bale.
        // Пока — заглушка, уточняем у менеджера.
        throw EdoException::operationNotSupported(
            'createUnstructuredDocument',
            self::PROVIDER_LABEL,
        );
    }

    public function createUcdDocument(EdoUcdStructuredRequest $request): EdoDocumentDto
    {
        // TODO(после ЛК + XML-билдер): сформировать XML УКД 5.01.
        throw EdoException::operationNotSupported(
            'createUcdDocument',
            self::PROVIDER_LABEL,
        );
    }

    public function acceptTitle(EdoAcceptTitleRequest $request): EdoFile
    {
        // POST /documents/provisional/buyer/title
        // Тело: { provisionalDocParams: {parentDocId, fingerprint},
        //         additionalParams: {docTypeId: 11|13, fingerprint,
        //                            parentDocId, svPrinSodOper, svPrinDataPrin} }
        // Ответ: { contentBase64, uuid, docTypeId }
        //
        // Нужен fingerprint сертификата. Пока — заглушка, ждём fingerprint
        // и ответ менеджера о flow подписи титула.
        throw EdoException::operationNotSupported(
            'acceptTitle',
            self::PROVIDER_LABEL,
        );
    }
}
