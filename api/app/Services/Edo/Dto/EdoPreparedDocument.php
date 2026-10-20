<?php

declare(strict_types=1);

namespace App\Services\Edo\Dto;

final readonly class EdoPreparedDocument
{
    public function __construct(
        public string $uuid,                    // uuid EdoDocument
        public string $payloadBase64,           // содержимое для подписи (XML от оператора)
        public string $payloadFilename,         // имя файла (для UI)
        public string $signatureHint,           // 'detached_cms' или что-то иное
    ) {
    }
}
