<?php

declare(strict_types=1);

namespace App\Services\Edo\Providers\EdoRu;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Exceptions\EdoException;
use App\Services\Edo\Dto\EdoDocumentDto;
use App\Services\Edo\Dto\EdoFile;
use App\Services\Edo\Dto\EdoOrganizationDto;
use App\Services\Edo\Dto\EdoSyncResult;

/**
 * Маппер между ответами API ЭДО.Поток и DTO MSTO.
 *
 * Типы документов (таблица из раздела 2.1.1):
 *   1  — Счёт-фактура / УПД(СЧФ)
 *   2  — Счёт-фактура корректировочный / УКД(КСЧФ)
 *   8  — УПД (СЧФДОП, ДОП)
 *   11 — Титул покупателя УПД
 *   12 — УКД (КСЧФДИС, ДИС)
 *   13 — Титул покупателя УКД
 *   30 — УПД (исправительный)
 *   31 — УКД (исправительный)
 *
 * Точный справочник — GET /dictionary/get-document-type.
 */
final class EdoRuMapper
{
    private const PROVIDER_LABEL = 'edoru';

    /** @var array<string, int> */
    private const DOC_TYPE_MAP = [
        EdoDocumentType::UpdSf->value    => 1,
        EdoDocumentType::UpdSfDop->value => 8,
        EdoDocumentType::UpdDop->value   => 8,
        EdoDocumentType::UcdCsf->value   => 12,
        EdoDocumentType::Invoice->value  => 1,
    ];

    /**
     * @return array<string, int>
     */
    public static function knownTypes(): array
    {
        return self::DOC_TYPE_MAP;
    }

    /**
     * Маппит наш внутренний тип в docType ЭДО.Поток.
     *
     * @throws EdoException
     */
    public function toDocType(EdoDocumentType $type): int
    {
        if (! isset(self::DOC_TYPE_MAP[$type->value])) {
            throw EdoException::operationNotSupported(
                'toDocType(' . $type->value . ')',
                self::PROVIDER_LABEL,
            );
        }

        return self::DOC_TYPE_MAP[$type->value];
    }

    /**
     * Обратный маппинг: docType ЭДО.Поток → наш EdoDocumentType.
     */
    public function fromDocType(int $docType): ?EdoDocumentType
    {
        $key = array_search($docType, self::DOC_TYPE_MAP, true);

        return $key === false ? null : EdoDocumentType::from($key);
    }

    /**
     * Ответ /contractors/search-contractors → EdoOrganizationDto.
     *
     * @param  array<string, mixed>  $row
     */
    public function toOrganization(array $row): EdoOrganizationDto
    {
        $id  = (string) ($row['clientId'] ?? '');
        $inn = (string) ($row['inn'] ?? '');

        if ($id === '' || $inn === '') {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'INVALID_CONTRACTOR',
                'Отсутствует clientId или inn в ответе /contractors/search-contractors',
            );
        }

        return new EdoOrganizationDto(
            id:           $id,
            name:         (string) ($row['fullName'] ?? ''),
            inn:          $inn,
            kpp:          isset($row['kpp']) && $row['kpp'] !== '' ? (string) $row['kpp'] : null,
            ogrn:         isset($row['ogrn']) && $row['ogrn'] !== '' ? (string) $row['ogrn'] : null,
            ediCode:      $id, // В ЭДО.Поток clientId и есть ediCode
            operatorCode: isset($row['operatorMnemo']) ? (string) $row['operatorMnemo'] : null,
        );
    }

    /**
     * Ответ /documents/document|find → EdoDocumentDto.
     *
     * @param  array<string, mixed>  $row
     */
    public function toDocument(array $row): EdoDocumentDto
    {
        $docId = $row['docId'] ?? null;

        if ($docId === null || $docId === '') {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'INVALID_DOCUMENT',
                'Отсутствует docId в ответе ЭДО.Поток',
            );
        }

        $type = $this->fromDocType((int) ($row['docTypeId'] ?? 0))
        ?? EdoDocumentType::NonFormalized;

        return new EdoDocumentDto(
            externalId:   (string) $docId,
            externalUuid: isset($row['guid']) && $row['guid'] !== '' ? (string) $row['guid'] : null,
            type:         $type,
            status:       $this->mapDocumentState($row),
            number:       (string) ($row['docNumber'] ?? ''),
            date:         $this->parseDate($row['docDate'] ?? null),
            senderName:   isset($row['fromOrgName']) ? (string) $row['fromOrgName'] : null,
            receiverName: isset($row['toOrgName']) ? (string) $row['toOrgName'] : null,
            content:      isset($row['content']) && $row['content'] !== ''
                ? EdoFile::fromBase64(
                (string) ($row['fileName'] ?? 'document.xml'),
                'text/xml',
                (string) $row['content'],
            )
                : null,
            raw:          $row,
        );
    }

    /**
     * Ответ /documents/find (для pullEvents) → EdoSyncResult.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toSyncResult(array $payload): EdoSyncResult
    {
        $rows   = (array) ($payload['data'] ?? []);
        $events = [];
        $lastEventId = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $events[] = $row;

            $id = (int) ($row['docId'] ?? 0);
            if ($id > $lastEventId) {
                $lastEventId = $id;
            }
        }

        return new EdoSyncResult(events: $events, lastEventId: $lastEventId);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapDocumentState(array $row): EdoDocumentStatus
    {
        // Документация v5.2 не даёт полного справочника docStateId/docStateName.
        // Пока ориентируемся на sfStateId/docStateId и строку docStateName.
        // Уточняется у менеджера / через GET /dictionary/get-document-type.
        $stateName = mb_strtolower((string) ($row['docStateName'] ?? ''));

        if ($stateName !== '') {
            if (str_contains($stateName, 'ожид') && str_contains($stateName, 'подпис')) {
                return EdoDocumentStatus::ToSign;
            }
            if (str_contains($stateName, 'доставлен') || str_contains($stateName, 'получен')) {
                return EdoDocumentStatus::Delivered;
            }
            if (str_contains($stateName, 'подписан') && str_contains($stateName, 'получател')) {
                return EdoDocumentStatus::Accepted;
            }
            if (str_contains($stateName, 'отклон') || str_contains($stateName, 'отказ')) {
                return EdoDocumentStatus::Rejected;
            }
            if (str_contains($stateName, 'черновик')) {
                return EdoDocumentStatus::Draft;
            }
            if (str_contains($stateName, 'отправлен')) {
                return EdoDocumentStatus::Sended;
            }
        }

        // Fallback по sfStateId: 0 — нет подписи, 2 — ожидает подписи покупателя,
        // 3 — подписан. Точное соответствие уточним.
        return match ((int) ($row['sfStateId'] ?? 0)) {
            0  => EdoDocumentStatus::Unknown,
            2  => EdoDocumentStatus::ToSign,
            3  => EdoDocumentStatus::Signed,
            default => EdoDocumentStatus::Unknown,
        };
    }

    private function parseDate(mixed $value): \DateTimeImmutable
    {
        if (is_string($value) && $value !== '') {
            foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
                $dt = \DateTimeImmutable::createFromFormat($format, $value);
                if ($dt !== false) {
                    return $dt;
                }
            }

            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
                // fallthrough
            }
        }

        return new \DateTimeImmutable();
    }
}
