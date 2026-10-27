<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSyncResult;

interface EdoReadProviderInterface
{
    public function getDocument(string $externalId): EdoDocumentDto;

    public function getDocumentContent(string $externalId): EdoFile;

    public function downloadPdf(string $externalId): EdoFile;

    public function pullEvents(int $fromEventId, int $count = 100): EdoSyncResult;

    /**
     * Список документов, относящихся к ЛК организации.
     *
     * @param array{dateFrom?: string, dateTo?: string, status?: string} $filters
     * @return array<int, EdoDocumentDto>
     */
    public function getDocumentList(array $filters = []): array;
}
