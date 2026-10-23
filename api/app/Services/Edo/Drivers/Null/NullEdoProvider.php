<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Null;

use App\Contracts\Edo\EdoProviderInterface;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Exceptions\EdoException;
use App\DTO\EdoAcceptTitleRequest;
use App\DTO\EdoDocumentDto;
use App\DTO\EdoFile;
use App\DTO\EdoOrganizationDto;
use App\DTO\EdoSignature;
use App\DTO\EdoStructuredRequest;
use App\DTO\EdoSyncResult;
use App\DTO\EdoUcdStructuredRequest;

final class NullEdoProvider implements EdoProviderInterface
{
    public function provider(): EdoProviderEnum
    {
        return EdoProviderEnum::None;
    }

    public function getSelfOrganization(): EdoOrganizationDto
    {
        throw EdoException::notConfigured();
    }

    public function findOrganization(string $inn, ?string $kpp = null): ?EdoOrganizationDto
    {
        throw EdoException::notConfigured();
    }

    public function createDocument(
        EdoDocumentType $type,
        EdoFile $content,
        string $number,
        \DateTimeImmutable $date,
        string $receiverEdiCode,
        ?EdoSignature $signature = null,
    ): EdoDocumentDto {
        throw EdoException::notConfigured();
    }

    public function addAttachment(string $externalId, EdoFile $file): void
    {
        throw EdoException::notConfigured();
    }

    public function signDocument(string $externalId, EdoSignature $signature): void
    {
        throw EdoException::notConfigured();
    }

    public function sendDocument(string $externalId): void
    {
        throw EdoException::notConfigured();
    }

    public function acceptDocument(string $externalId, EdoSignature $ticket): void
    {
        throw EdoException::notConfigured();
    }

    public function rejectDocument(string $externalId, string $comment): void
    {
        throw EdoException::notConfigured();
    }

    public function getDocument(string $externalId): EdoDocumentDto
    {
        throw EdoException::notConfigured();
    }

    public function getDocumentContent(string $externalId): EdoFile
    {
        throw EdoException::notConfigured();
    }

    public function downloadPdf(string $externalId): EdoFile
    {
        throw EdoException::notConfigured();
    }

    public function pullEvents(int $fromEventId, int $count = 100): EdoSyncResult
    {
        throw EdoException::notConfigured();
    }

    public function createStructuredDocument(EdoStructuredRequest $request): EdoDocumentDto
    {
        throw EdoException::notConfigured();
    }

    public function createUcdDocument(EdoUcdStructuredRequest $request): EdoDocumentDto
    {
        throw EdoException::operationNotSupported(
            'createUcdDocument(' . $request->type->value . ')',
            'null',
        );
    }

    public function acceptTitle(EdoAcceptTitleRequest $request): EdoFile
    {
        throw EdoException::operationNotSupported(
            'acceptTitle(' . $request->externalId . ')',
            'null',
        );
    }

    public function createUnstructuredDocument(
        EdoDocumentType $type,
        EdoFile $content,
        string $number,
        \DateTimeImmutable $date,
        string $receiverEdiCode,
        ?EdoSignature $signature = null,
    ): EdoDocumentDto {
        throw EdoException::operationNotSupported(
            'createUnstructuredDocument(' . $type->value . ')',
            'null',
        );
    }
}