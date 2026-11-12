<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Taxcom;

use App\Contracts\Edo\EdoAccountProviderInterface;
use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\Contracts\Edo\EdoReadProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\DTO\EdoSyncResult;
use App\Exceptions\EdoException;
use App\Models\Organization;

final class TaxcomProvider implements
    EdoOutboundProviderInterface,
    EdoReadProviderInterface,
    EdoAccountProviderInterface
{
    public function __construct(
        private readonly TaxcomClient $client,
        private readonly TaxcomMapper $mapper,
    ) {}

    public function getDocumentList(array $filters = []): array
    {
        $query = [
            'DateFrom' => $filters['dateFrom'] ?? now()->subMonth()->format('d.m.Y'),
            'DateTo'   => $filters['dateTo'] ?? now()->format('d.m.Y'),
        ];

        $response = $this->client->get('/API/GetMessageList', $query);
        $rows = $this->parseDocumentList($response);

        return array_map(fn (array $row) => $this->mapper->toDocument($row), $rows);
    }

    /* --- Заглушки для остальных методов --- */

    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        throw EdoException::operationNotSupported('createStructuredDocument', 'taxcom');
    }

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        throw EdoException::operationNotSupported('addAttachment', 'taxcom');
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        throw EdoException::operationNotSupported('signDocument', 'taxcom');
    }

    public function sendDocument(string $externalId): void
    {
        throw EdoException::operationNotSupported('sendDocument', 'taxcom');
    }

    public function getDocument(string $externalId): EdoDocumentDto
    {
        throw EdoException::operationNotSupported('getDocument', 'taxcom');
    }

    public function getDocumentContent(string $externalId): EdoFile
    {
        throw EdoException::operationNotSupported('getDocumentContent', 'taxcom');
    }

    public function downloadPdf(string $externalId): EdoFile
    {
        throw EdoException::operationNotSupported('downloadPdf', 'taxcom');
    }

    public function createNonFormalizedDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        // TODO: Implement createNonFormalizedDocument()
    }

    public function pullEvents(int $fromEventId, int $count = 100): EdoSyncResult
    {
        // TODO: Implement pullEvents()
    }

    public function connect(Organization $org, array $credentials): void
    {
        // TODO: Implement connect()
    }

    private function parseDocumentList(array $response): array
    {
        return $response['Documents'] ?? [];
    }
}
