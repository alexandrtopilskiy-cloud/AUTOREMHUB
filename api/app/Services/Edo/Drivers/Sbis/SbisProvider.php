<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
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

        $uuid = Str::uuid()->toString();
        $metadata = SbisXmlMetadataExtractor::extractUpdMetadata($xmlContent);
        $externalId = "{$dto->number}-$uuid";
        $logger->info("EXTRANAL ID = $externalId");

        $document = [
            'Идентификатор' => $externalId,
            'Дата'          => $metadata['Дата'],
            'Номер'         => $metadata['Номер'],
            'Тип'           => $metadata['Тип'],
            'Вложение'      => [
                [
                    'Идентификатор'    => $uuid,
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

        $response = $this->client->request('СБИС.ЗаписатьДокумент', ['Документ' => $document]);
        $sbisInternalId = $response['Идентификатор'] ?? $externalId;

        return new EdoDocumentDto(
            externalId: $externalId,
            externalUuid: $sbisInternalId,
            number: $metadata['Номер'],
            date: $dto->date,
            type: $dto->type,
            status: EdoDocumentStatus::Sended,
            senderName: $dto->sender->name,
            senderInn: $dto->sender->inn,
            receiverName: $dto->receiver->name,
            receiverInn: $dto->receiver->inn,
            raw: $response
        );
    }

    /**
     * Создает неформализованный документ (например, Заказ-наряд) в СБИС.
     * Отправляет PDF-файл напрямую, без парсинга XML.
     */
    public function createNonFormalizedDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        $logger = Log::channel('edo');
        $logger->info('CREATE NON FORMALIZED DOCUMENT');
        $pdfContent = $request->meta['file_content'];
        $fileName = $request->meta['file_name'];

        if (!$pdfContent) {
            throw new RuntimeException("В запросе отсутствует meta['file_content'] (PDF).");
        }

        // СБИС требует UUID для идентификатора документа
        $externalId = Str::uuid()->toString();

        $document = [
            'Идентификатор' => $externalId,
            'Дата'          => $request->date->format('d.m.Y'),
            'Номер'         => $request->number,
            'Тип'           => 'SimpleDoc',
            'Примечание'    => 'Документ по заявке (Заказ-наряд)',

            // Вложение с PDF-файлом
            'Вложение'      => [
                [
                    'Идентификатор' => Str::uuid()->toString(),
                    'Файл'          => [
                        'Имя'            => $fileName,
                        'ДвоичныеДанные' => $pdfContent, // Уже в base64 из билдера
                    ],
                ]
            ],

            // Организации (отправитель и получатель)
            'НашаОрганизация' => [
                'СвЮЛ' => [
                    'ИНН'      => $request->sender->inn,
                    'КПП'      => $request->sender->kpp,
                    'Название' => $request->sender->name,
                ]
            ],
            'Контрагент' => [
                'СвЮЛ' => [
                    'ИНН'      => $request->receiver->inn,
                    'КПП'      => $request->receiver->kpp,
                    'Название' => $request->receiver->name,
                ]
            ],
        ];

        // Отправляем запрос в СБИС
        $response = $this->client->request('СБИС.ЗаписатьДокумент', ['Документ' => $document]);
        $sbisInternalId = $response['Идентификатор'] ?? $externalId;

        return new EdoDocumentDto(
            externalId: $externalId,
            externalUuid: $sbisInternalId,
            number: $request->number,
            date: $request->date,
            type: $request->type,
            status: EdoDocumentStatus::Draft,
            senderName: $request->sender->name,
            senderInn: $request->sender->inn,
            receiverName: $request->receiver->name,
            receiverInn: $request->receiver->inn,
            raw: $response
        );
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        // 1. Подготавливаем действие подписания
        $prepared = $this->client->request('СБИС.ПодготовитьДействие', [
            'Документ' => ['Идентификатор' => $externalId],
            'Действие' => 'Подписать',
        ]);

        // 2. Выполняем (передаем сертификат и подпись)
        $this->client->request('СБИС.ВыполнитьДействие', [
            'Документ' => ['Идентификатор' => $externalId],
            'Действие' => 'Подписать',
            'Параметры' => $prepared, // Данные из первого шага
            'Подпись'   => $signature->base64, // Ваша подпись
        ]);
    }

    public function sendDocument(string $externalId): void
    {
        // 1. Подготавливаем действие подписания
        $prepared = $this->client->request('СБИС.ПодготовитьДействие', [
            'Документ' => ['Идентификатор' => $externalId],
            'Действие' => 'Отправить',
        ]);

        // 2. Выполняем (передаем сертификат и подпись)
        $this->client->request('СБИС.ВыполнитьДействие', [
            'Документ' => ['Идентификатор' => $externalId],
            'Действие' => 'Отправить',
            'Параметры' => $prepared,
        ]);
    }
}
