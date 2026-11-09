<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use DateTimeImmutable;

final readonly class EdoDocumentDto
{
    public function __construct(
        // Идентификаторы
        public string $externalId,           // Наш ID в провайдере (например, 'ZR-...')
        public ?string $externalUuid = null, // ID в СБИС (внутренний GUID)
        public ?string $uuid = null,         // UUID нашей записи в БД

        // Данные документа
        public ?string $number = null,
        public ?DateTimeImmutable $date = null,
        public ?EdoDocumentType $type = null,
        public ?EdoDocumentStatus $status = null,

        // Участники
        public ?string $senderName = null,
        public ?string $senderInn = null,
        public ?string $receiverName = null,
        public ?string $receiverInn = null,

        // Данные для вложений и метаданных
        public ?EdoFile $content = null,     // Сам файл (если нужно скачать/прочитать)
        public array $raw = [],              // Сырой ответ от API провайдера

        // ID вложений в вашей БД (заполняются после сохранения)
        public ?int $payloadAttachmentId = null,
        public ?int $signedAttachmentId = null,
        public ?int $acceptanceAttachmentId = null,
    ) {}
}
