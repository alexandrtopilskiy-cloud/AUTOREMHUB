<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SbisProvider implements EdoOutboundProviderInterface
{
    public function __construct(
        private readonly SbisClient $client
    ) {}

    public function createStructuredDocument(EdoStructuredRequest $dto): EdoDocumentDto
    {
        $logger = Log::channel('edo');
        $xmlContent = Arr::get($dto->meta, 'xml_content');
        if (!$xmlContent) {
            throw new RuntimeException("В запросе отсутствует meta['xml_content'].");
        }

        $metadata = SbisXmlMetadataExtractor::extractUpdMetadata($xmlContent);
        $externalId = 'ZR-' . $dto->number . "-" . Str::uuid()->toString();
        $logger->info("EXTRANAL ID = $externalId");

        // 1. Формируем объект "Документ"
        $document = [
            'Идентификатор' => $externalId,
            'Дата'          => $metadata['Дата'],
            'Номер'         => $metadata['Номер'],
            'Тип'           => $metadata['Тип'],
            'Вложение'      => [
                [
                    'Идентификатор'    => Str::uuid()->toString(),
                    'Файл'             => [
                        'Имя'            => 'document.xml',
                        'ДвоичныеДанные' => base64_encode($xmlContent),
                    ],
                ]
            ],
            'НашаОрганизация' => [
                'СвЮЛ' => [
                    'ИНН' => $dto->sender->inn,
                    'КПП' => $dto->sender->kpp,
                    'Название' => $dto->sender->name,
                ]
            ],
            'Контрагент' => [
                'СвЮЛ' => [
                    'ИНН' => $dto->receiver->inn,
                    'КПП' => $dto->receiver->kpp,
                    'Название' => $dto->receiver->name,
                ]
            ]
        ];

        // 2. Вызываем клиент
        $response = $this->client->request('СБИС.ЗаписатьДокумент', ['Документ' => $document]);
        $sbisInternalId = $response['Идентификатор'] ?? $externalId;

        // В мок-режиме возвращаем статус sended (если config позволяет)
        // В реальном — draft
        $isMock = config('edo.mock', false);
        $status = $isMock ? EdoDocumentStatus::Sended : EdoDocumentStatus::Draft;

        return new EdoDocumentDto(
            externalId: $externalId,
            externalUuid: $sbisInternalId,
            number: $metadata['Номер'],
            date: $dto->date,
            type: $dto->type,
            status: $status,
            senderName: $dto->sender->name,
            senderInn: $dto->sender->inn,
            receiverName: $dto->receiver->name,
            receiverInn: $dto->receiver->inn,
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
