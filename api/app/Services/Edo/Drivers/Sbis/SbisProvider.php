<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Contracts\Edo\EdoNonFormalizedProviderInterface;
use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoOrganizationDto;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * СБИС: документы записываются методом СБИС.ЗаписатьДокумент. Повторная
 * запись с тем же «Идентификатор» обновляет документ, а не создаёт новый.
 * Документ остаётся в СБИС черновиком исходящих: подписание и отправка
 * контрагенту — в интерфейсе СБИС (или sendDocument()).
 */
final class SbisProvider implements EdoOutboundProviderInterface, EdoNonFormalizedProviderInterface
{
    public function __construct(
        private readonly SbisClient $client,
    ) {}

    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        $xmlContent = (string) Arr::get($request->meta, 'xml_content', '');
        if ($xmlContent === '') {
            throw new RuntimeException("В запросе отсутствует meta['xml_content'].");
        }

        $metadata = SbisXmlMetadataExtractor::extractUpdMetadata($xmlContent);
        $externalId = $this->externalId($request);

        $response = $this->client->request('СБИС.ЗаписатьДокумент', ['Документ' => [
            'Идентификатор'   => $externalId,
            'Дата'            => $metadata['Дата'],
            'Номер'           => $metadata['Номер'],
            'Тип'             => $metadata['Тип'],
            'Вложение'        => [[
                'Идентификатор' => (string) Str::uuid(),
                'Файл'          => [
                    'Имя'            => (string) ($request->meta['file_name'] ?? 'document.xml'),
                    'ДвоичныеДанные' => base64_encode($xmlContent),
                ],
            ]],
            'НашаОрганизация' => $this->party($request->sender),
            'Контрагент'      => $this->party($request->receiver),
        ]]);

        return $this->result($request, $externalId, $response, $metadata['Номер']);
    }

    public function createNonFormalizedDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        $pdfContent = (string) ($request->meta['file_content'] ?? '');
        if ($pdfContent === '') {
            throw new RuntimeException("В запросе отсутствует meta['file_content'] (PDF).");
        }

        $externalId = $this->externalId($request);

        $response = $this->client->request('СБИС.ЗаписатьДокумент', ['Документ' => [
            'Идентификатор'   => $externalId,
            'Дата'            => $request->date->format('d.m.Y'),
            'Номер'           => $request->number,
            'Тип'             => 'SimpleDoc',
            'Примечание'      => (string) ($request->meta['note'] ?? ''),
            'Вложение'        => [[
                'Идентификатор' => (string) Str::uuid(),
                'Файл'          => [
                    'Имя'            => (string) ($request->meta['file_name'] ?? ($request->number . '.pdf')),
                    'ДвоичныеДанные' => $pdfContent, // уже base64
                ],
            ]],
            'НашаОрганизация' => $this->party($request->sender),
            'Контрагент'      => $this->party($request->receiver),
        ]]);

        return $this->result($request, $externalId, $response, $request->number);
    }

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        // Вложения передаются в СБИС.ЗаписатьДокумент вместе с документом.
        Log::channel('edo')->info("SBIS: addAttachment не используется ({$externalId})");
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        $this->action($externalId, 'Подписать', ['Подпись' => $signature->content]);
    }

    public function sendDocument(string $externalId): void
    {
        $this->action($externalId, 'Отправить');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function action(string $externalId, string $action, array $extra = []): void
    {
        $prepared = $this->client->request('СБИС.ПодготовитьДействие', [
            'Документ' => ['Идентификатор' => $externalId],
            'Действие' => $action,
        ]);

        $this->client->request('СБИС.ВыполнитьДействие', [
            'Документ'  => ['Идентификатор' => $externalId],
            'Действие'  => $action,
            'Параметры' => $prepared,
            ...$extra,
        ]);
    }

    private function externalId(EdoStructuredRequest $request): string
    {
        $id = (string) ($request->meta['external_id'] ?? '');

        return $id !== '' ? $id : (string) Str::uuid();
    }

    /**
     * @return array<string, mixed>
     */
    private function party(EdoOrganizationDto $org): array
    {
        if (strlen($org->inn) === 12) {
            return ['СвФЛ' => ['ИНН' => $org->inn, 'Название' => $org->name]];
        }

        return ['СвЮЛ' => ['ИНН' => $org->inn, 'КПП' => (string) $org->kpp, 'Название' => $org->name]];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function result(EdoStructuredRequest $request, string $externalId, array $response, string $number): EdoDocumentDto
    {
        return new EdoDocumentDto(
            externalId: $externalId,
            externalUuid: is_string($response['Идентификатор'] ?? null) ? $response['Идентификатор'] : null,
            type: $request->type,
            status: EdoDocumentStatus::Draft,
            number: $number,
            date: $request->date,
            senderName: $request->sender->name,
            receiverName: $request->receiver->name,
            raw: ['url' => $response['СсылкаДляНашаОрганизация'] ?? ($response['СсылкаВКабинет'] ?? null)],
        );
    }
}
