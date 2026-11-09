<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use App\Enums\Edo\EdoDocumentStatus;

class SbisProvider implements EdoOutboundProviderInterface
{
    private string $baseUrl;
    private string $sessionId;
    private bool $isMock;

    public function __construct()
    {
        $config = config('edo.providers.' . EdoProvider::Sbis->value);
        $this->baseUrl = $config['base_url'] ?? 'https://online.sbis.ru/service/?srv=1';
        $this->sessionId = $config['session_id'] ?? '';
        $this->isMock = (bool)($config['mock'] ?? false);
    }

    public function isMock(): bool
    {
        return $this->isMock;
    }

    private function request(string $method, array $params = []): array
    {
        if ($this->isMock) {
            Log::channel('edo')->info("SbisProvider [MOCK]: Вызов метода {$method}", ['params' => $params]);
            if ($method === 'СБИС.ЗаписатьДокумент') {
                return ['Идентификатор' => 'mock-' . Str::uuid()->toString()];
            }
            return [];
        }
        throw new RuntimeException('Реальный запрос к СБИС пока не реализован.');
    }

    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        $xmlContent = $request->meta['xml_content'] ?? null;
        if (!$xmlContent) {
            throw new RuntimeException("В запросе отсутствует meta['xml_content'].");
        }

        $metadata = SbisXmlMetadataExtractor::extractUpdMetadata($xmlContent);
        $externalId = 'ZR-' . Str::uuid()->toString();

        $document = [
            'Идентификатор' => $externalId,
            'Дата'          => $metadata['Дата'],
            'Номер'         => $metadata['Номер'],
            'Тип'           => $metadata['Тип'],
            'Подтип'        => $metadata['Подтип'],
            'Название'      => $metadata['Название'],
            'Вложение'      => [
                [
                    'Идентификатор'    => Str::uuid()->toString(),
                    'Тип'              => $metadata['Тип'],
                    'ВерсияФормата'    => $metadata['Версия'],
                    'ПодВерсияФормата' => $metadata['ПодВерсия'],
                    'Название'         => $metadata['Название'],
                    'Файл'             => [
                        'Имя'            => 'document.xml',
                        'ДвоичныеДанные' => base64_encode($xmlContent),
                    ],
                ]
            ],
            'НашаОрганизация' => [
                'СвЮЛ' => ['ИНН' => $request->sender->inn, 'КПП' => $request->sender->kpp]
            ],
            'Контрагент' => [
                'СвЮЛ' => ['ИНН' => $request->receiver->inn, 'КПП' => $request->receiver->kpp]
            ]
        ];

        $response = $this->request('СБИС.ЗаписатьДокумент', ['Документ' => $document]);
        $sbisInternalId = $response['Идентификатор'] ?? $externalId;

        $status = $this->isMock ? EdoDocumentStatus::Sended : EdoDocumentStatus::Draft;

        return new EdoDocumentDto(
            externalId: $externalId,
            externalUuid: $sbisInternalId,
            number: $metadata['Номер'],
            date: $request->date,
            type: $request->type,
            status: $status,
            senderName: $request->sender->name,
            senderInn: $request->sender->inn,
            receiverName: $request->receiver->name,
            receiverInn: $request->receiver->inn,
            raw: $response
        );
    }

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        Log::channel('edo')->info("SbisProvider: Добавление вложения к {$externalId}");
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        Log::channel('edo')->info("SbisProvider: Подписание документа {$externalId}");
    }

    public function sendDocument(string $externalId): void
    {
        Log::channel('edo')->info("SbisProvider: Отправка документа {$externalId}");
    }
}
