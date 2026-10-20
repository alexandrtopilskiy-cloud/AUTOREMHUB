<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Services\Edo\Dto\EdoAcceptTitleRequest;
use App\Services\Edo\Dto\EdoDocumentDto;
use App\Services\Edo\Dto\EdoFile;
use App\Services\Edo\Dto\EdoOrganizationDto;
use App\Services\Edo\Dto\EdoSignature;
use App\Services\Edo\Dto\EdoStructuredRequest;
use App\Services\Edo\Dto\EdoSyncResult;
use App\Services\Edo\Dto\EdoUcdStructuredRequest;

interface EdoProviderInterface
{
    public function provider(): EdoProviderEnum;

    /* ─── Организации ─── */

    public function getSelfOrganization(): EdoOrganizationDto;

    public function findOrganization(string $inn, ?string $kpp = null): ?EdoOrganizationDto;

    /* ─── Исходящие документы ─── */

    public function addAttachment(string $externalId, EdoFile $file): void;

    public function signDocument(string $externalId, EdoSignature $signature): void;

    public function sendDocument(string $externalId): void;

    /* ─── Входящие действия ─── */

    public function acceptDocument(string $externalId, EdoSignature $ticket): void;

    public function rejectDocument(string $externalId, string $comment): void;

    /* ─── Чтение ─── */

    public function getDocument(string $externalId): EdoDocumentDto;

    public function getDocumentContent(string $externalId): EdoFile;

    public function downloadPdf(string $externalId): EdoFile;

    /* ─── События ─── */

    public function pullEvents(int $fromEventId, int $count = 100): EdoSyncResult;

    /**
     * Создать структурированный документ (УПД, УКД, Акт).
     * Оператор сам генерирует XML по XSD ФНС.
     * Возвращает EdoDocumentDto с content = XML для подписи.
     */
    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto;

    /**
     * Создать неструктурированный документ (договор, заказ-наряд, доверенность).
     * XML генерируется на нашей стороне.
     */
    public function createUnstructuredDocument(
        EdoDocumentType $type,
        EdoFile $content,
        string $number,
        \DateTimeImmutable $date,
        string $receiverEdiCode,
    ): EdoDocumentDto;

    public function createUcdDocument(EdoUcdStructuredRequest $request): EdoDocumentDto;

    public function acceptTitle(EdoAcceptTitleRequest $request): EdoFile;
}
