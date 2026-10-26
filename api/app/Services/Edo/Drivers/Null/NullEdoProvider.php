<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Null;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class NullEdoProvider implements EdoOutboundProviderInterface
{
    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        $externalId = 'MOCK-' . Str::uuid()->toString();

        Log::info('MOCK: Создан структурированный документ', [
            'type' => $request->type->value,
            'externalId' => $externalId,
        ]);

        return new EdoDocumentDto(
            externalId: $externalId,
            externalUuid: null,
            type: $request->type,
            status: EdoDocumentStatus::Draft,
            number: $request->number,
            date: $request->date,
            senderName: $request->sender->name ?? null,
            receiverName: $request->receiver->name ?? null,
            content: null,
            raw: ['status' => 'success', 'mock' => true],
        );
    }

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        Log::info("MOCK: Добавлено вложение к $externalId", [
            'file_name' => $file->filename,
        ]);
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        Log::info("MOCK: Документ $externalId подписан");
    }

    public function sendDocument(string $externalId): void
    {
        Log::info("MOCK: Документ $externalId успешно отправлен контрагенту");
    }

    public function code(): string
    {
        return 'diadoc';
        return 'mock';
    }
}
