<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Contracts\Edo\EdoNonFormalizedProviderInterface;
use App\DTO\EdoDocumentDto;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Enums\RequestDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestDocument;
use App\Services\Edo\Builders\Closing\InvoiceBuilder;
use App\Services\Edo\Builders\Closing\UpdBuilder;
use App\Services\Edo\Builders\Closing\WorkOrderBuilder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Отправка закрывающих документов ЗР оператору ЭДО (из feature/sbis_edo):
 * ЗН и Счёт — неформализованные PDF, УПД — формализованный XML 5.03.
 * Пакеты: СТО → УК (…/1) и УК → Заказчик (…/2). Ошибка одного документа
 * не мешает остальным; состояние каждого — в edo_documents (source = RequestDocument).
 */
final class EdoClosingDocumentsService
{
    /** Документы ЗР, которые уходят в ЭДО (порядок отправки). */
    public const TYPES = [
        RequestDocumentType::StationUpd,
        RequestDocumentType::StationInvoice,
        RequestDocumentType::StationWorkOrder,
        RequestDocumentType::CustomerUpd,
        RequestDocumentType::CustomerInvoice,
        RequestDocumentType::CustomerWorkOrder,
    ];

    public function __construct(
        private readonly EdoDispatcher $dispatcher,
        private readonly WorkOrderBuilder $workOrderBuilder,
        private readonly InvoiceBuilder $invoiceBuilder,
        private readonly UpdBuilder $updBuilder,
    ) {}

    /**
     * @return array<string, string> номер документа → итог (written | skipped | validated | error: …)
     */
    public function send(RepairRequest $request, bool $validateOnly = false): array
    {
        $documents = $request->documents()
            ->whereIn('type', array_map(static fn (RequestDocumentType $type): string => $type->value, self::TYPES))
            ->with(['seller', 'buyer', 'attachment'])
            ->get();

        $result = [];

        foreach (self::TYPES as $type) {
            $document = $documents->first(fn (RequestDocument $doc): bool => $doc->type === $type);
            if ($document === null) {
                continue;
            }

            $result[$document->number] = $this->sendOne($request, $document, $validateOnly);
        }

        return $result;
    }

    private function sendOne(RepairRequest $request, RequestDocument $document, bool $validateOnly): string
    {
        $logger = Log::channel('edo');
        $sender = $document->seller;
        $receiver = $document->buyer;

        if (!$sender instanceof Organization || !$receiver instanceof Organization) {
            $logger->warning("ЭДО: у документа {$document->number} нет отправителя или получателя");

            return 'error: нет реквизитов сторон';
        }

        $edo = $this->record($request, $document, $sender, $receiver);
        $mock = (bool) config('edo.mock', true);

        try {
            $provider = $this->dispatcher->forOrganization($sender);
        } catch (Throwable $e) {
            $logger->warning("ЭДО: документ {$document->number} не отправлен — {$e->getMessage()}");
            $this->mark($edo, 'skipped', EdoDocumentStatus::Unknown, $e->getMessage());

            return 'skipped';
        }

        try {
            if ($this->isUpd($document)) {
                $xml = $this->updBuilder->xml($document, $sender, $receiver);
                if ($validateOnly) {
                    $logger->info("ЭДО: УПД {$document->number} прошёл проверку XSD (--validate)");

                    return 'validated';
                }
                $dto = $provider->createStructuredDocument(
                    $this->updBuilder->request($document, $xml, $sender, $receiver, $edo->external_id),
                );
            } else {
                if (!$provider instanceof EdoNonFormalizedProviderInterface) {
                    throw new RuntimeException('Оператор ЭДО не принимает неформализованные документы');
                }
                $pdf = $this->pdf($document);
                if ($validateOnly) {
                    return 'validated';
                }
                $edoRequest = $this->isWorkOrder($document)
                    ? $this->workOrderBuilder->request($document, $pdf, $sender, $receiver, $edo->external_id)
                    : $this->invoiceBuilder->request($document, $pdf, $sender, $receiver, $edo->external_id);
                $dto = $provider->createNonFormalizedDocument($edoRequest);
            }

            $this->written($edo, $dto, $mock);
            $logger->info("ЭДО: документ {$document->number} записан", ['external_id' => $dto->externalId, 'mock' => $mock]);

            return 'written';
        } catch (Throwable $e) {
            $logger->error("ЭДО: ошибка документа {$document->number}", ['message' => $e->getMessage()]);
            $this->mark($edo, 'error', EdoDocumentStatus::Unknown, mb_substr($e->getMessage(), 0, 1000));

            return 'error: ' . $e->getMessage();
        }
    }

    /** PDF, сохранённый в ЗР (тот же файл, что скачивается из карточки). */
    private function pdf(RequestDocument $document): string
    {
        $attachment = $document->attachment;
        if ($attachment === null) {
            throw new RuntimeException("PDF документа {$document->number} не найден");
        }

        $content = \Illuminate\Support\Facades\Storage::disk($attachment->disk)->get($attachment->object_key);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException("Файл PDF документа {$document->number} пуст");
        }

        return $content;
    }

    private function record(RepairRequest $request, RequestDocument $document, Organization $sender, Organization $receiver): EdoDocument
    {
        /** @var EdoDocument $edo */
        $edo = EdoDocument::query()->firstOrNew([
            'source_type' => RequestDocument::class,
            'source_id'   => $document->id,
        ]);

        $provider = $this->dispatcher->providerFor($sender);
        if ($edo->exists && $edo->provider !== $provider) {
            // Сменился оператор — документ у нового оператора создаётся заново.
            $edo->external_id = null;
            $edo->external_uuid = null;
        }

        $edo->fill([
            'tenant_id'       => $request->tenant_id,
            'organization_id' => $sender->id,
            'counterparty_id' => $receiver->id,
            'document_type'   => $this->edoType($document),
            'provider'        => $provider,
            'number'          => $document->number,
            'document_date'   => $document->document_date->toDateString(),
        ]);
        // PDF документа ЗР — скачивание из раздела «Документы ЭДО».
        $edo->payload_attachment_id = $document->attachment_id;
        if (!$edo->exists) {
            $edo->status = EdoDocumentStatus::Draft;
            $edo->meta = ['state' => 'queued', 'repair_request_id' => $request->id];
        }
        $edo->save();

        return $edo;
    }

    private function written(EdoDocument $edo, EdoDocumentDto $dto, bool $mock): void
    {
        $edo->forceFill([
            'external_id'   => $dto->externalId,
            'external_uuid' => $dto->externalUuid,
            'status'        => $dto->status,
            'sent_at'       => now(),
            'meta'          => [
                ...(is_array($edo->meta) ? $edo->meta : []),
                'state' => 'written',
                'mock'  => $mock,
                'error' => null,
                'url'   => $dto->raw['url'] ?? null,
            ],
        ])->save();
    }

    private function mark(EdoDocument $edo, string $state, EdoDocumentStatus $status, string $error): void
    {
        $edo->forceFill([
            'status' => $edo->external_id !== null ? $edo->status : $status,
            'meta'   => [
                ...(is_array($edo->meta) ? $edo->meta : []),
                'state' => $state,
                'error' => $error,
            ],
        ])->save();
    }

    private function isUpd(RequestDocument $document): bool
    {
        return in_array($document->type, [RequestDocumentType::StationUpd, RequestDocumentType::CustomerUpd], true);
    }

    private function isWorkOrder(RequestDocument $document): bool
    {
        return in_array($document->type, [RequestDocumentType::StationWorkOrder, RequestDocumentType::CustomerWorkOrder], true);
    }

    private function edoType(RequestDocument $document): EdoDocumentType
    {
        return match (true) {
            $this->isUpd($document)       => EdoDocumentType::UpdSfDop,
            $this->isWorkOrder($document) => EdoDocumentType::WorkOrder,
            default                       => EdoDocumentType::NonFormalized,
        };
    }
}
