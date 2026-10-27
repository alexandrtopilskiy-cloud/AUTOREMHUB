<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Taxcom;

use App\Contracts\Edo\EdoAccountProviderInterface;
use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\Contracts\Edo\EdoProviderInterface;
use App\Contracts\Edo\EdoReadProviderInterface;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Exceptions\EdoException;
use App\DTO\EdoAccountInfoDto;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
    use App\DTO\EdoSyncResult;


final class TaxcomProvider implements
    EdoProviderInterface,
    EdoOutboundProviderInterface,
    EdoReadProviderInterface,
    EdoAccountProviderInterface
{
    public function __construct(
        private readonly TaxcomClient $client,
        private readonly TaxcomMapper $mapper,
    ) {}

    public function provider(): EdoProviderEnum
    {
        return EdoProviderEnum::Taxcom;
    }

    /* --- Состояние ЛК --- */

    public function getAccountInfo(): EdoAccountInfoDto
    {
        // GET /API/GetMyAccountInfo
        $response = $this->client->get('/API/GetMyAccountInfo');

        // Такском возвращает XML. Парсим (пока — простой регэксп, позже — DOMDocument).
        $body = $response['_raw'] ?? '';
        $data = $this->parseXml($body);

        return new EdoAccountInfoDto(
            login: (string) ($data['Login'] ?? ''),
            isBlocked: filter_var($data['IsBlocked'] ?? false, FILTER_VALIDATE_BOOLEAN),
            tariffName: $data['TariffName'] ?? null,
            tariffExpiredAt: $data['TariffExpiredAt'] ?? null,
            availableDocuments: (int) ($data['AvailableDocuments'] ?? 0),
            consumedDocuments: (int) ($data['ConsumedDocuments'] ?? 0),
            balance: isset($data['Balance']) ? (float) $data['Balance'] : null,
        );
    }

    public function isAuthenticated(): bool
    {
        try {
            $this->client->get('/API/GetMyAccountInfo');
            return true;
        } catch (EdoException) {
            return false;
        }
    }

    /* --- Список документов --- */

    public function getDocumentList(array $filters = []): array
    {
        // GET /API/GetMessageList?DateFrom=...&DateTo=...
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

    public function pullEvents(int $fromEventId, int $count = 100): EdoSyncResult
    {
        throw EdoException::operationNotSupported('pullEvents', 'taxcom');
    }

    /* --- Вспомогательные --- */

    private function parseXml(string $xml): array
    {
        $data = [];
        if (preg_match_all('/<(\w+)>(.*?)<\/\1>/s', $xml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $data[$match[1]] = $match[2];
            }
        }
        return $data;
    }

    private function parseDocumentList(array $response): array
    {
        // Пока предполагаем, что ответ — массив в JSON.
        // Для XML нужно будет написать парсер.
        return $response['Documents'] ?? [];
    }
}
