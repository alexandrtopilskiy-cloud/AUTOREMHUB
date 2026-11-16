<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoProvider;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Models\RequestDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin EdoDocument */
final class EdoDocumentResource extends JsonResource
{
    /** @var array<int, RepairRequest> ЗР по id edo_documents, см. preloadRequests() */
    private static array $requests = [];

    /**
     * Заранее находит ЗР для списка документов (без запроса на каждую строку):
     * meta.repair_request_id → документ ЗР (RequestDocument) → сама ЗР.
     *
     * @param Collection<int, EdoDocument> $documents
     */
    public static function preloadRequests(Collection $documents): void
    {
        $requestIdByEdo = [];
        $requestDocumentIds = [];

        foreach ($documents as $document) {
            $metaId = (int) (($document->meta ?? [])['repair_request_id'] ?? 0);
            if ($metaId > 0) {
                $requestIdByEdo[$document->id] = $metaId;
            } elseif ($document->source_type === RepairRequest::class) {
                $requestIdByEdo[$document->id] = (int) $document->source_id;
            } elseif ($document->source_type === RequestDocument::class) {
                $requestDocumentIds[$document->id] = (int) $document->source_id;
            }
        }

        if ($requestDocumentIds !== []) {
            $owners = RequestDocument::query()
                ->whereIn('id', array_values($requestDocumentIds))
                ->pluck('repair_request_id', 'id');
            foreach ($requestDocumentIds as $edoId => $documentId) {
                if (isset($owners[$documentId])) {
                    $requestIdByEdo[$edoId] = (int) $owners[$documentId];
                }
            }
        }

        $requests = RepairRequest::query()
            ->whereIn('id', array_values(array_unique($requestIdByEdo)))
            ->get()
            ->keyBy('id');

        self::$requests = [];
        foreach ($requestIdByEdo as $edoId => $requestId) {
            if (isset($requests[$requestId])) {
                self::$requests[$edoId] = $requests[$requestId];
            }
        }
    }

    public function toArray(Request $request): array
    {
        $outgoing = $this->organization_id === $request->user()->organization_id;
        [$action, $actionOwner] = self::expectedAction($this->status, $outgoing);
        $repairRequest = self::$requests[$this->id] ?? null;
        $meta = is_array($this->meta) ? $this->meta : [];

        return [
            'id'              => $this->uuid,
            'type'            => $this->document_type,
            'typeLabel'       => $this->document_type->label(),
            'number'          => $this->number,
            'date'            => $this->document_date->format('d.m.Y'),
            'documentDate'    => $this->document_date->format('Y-m-d'),
            'createdAt'       => $this->created_at?->toISOString(),
            'provider'        => $this->provider,
            'providerLabel'   => $this->provider->label(),
            'externalId'      => $this->external_id,
            'url'             => $outgoing ? ($meta['url'] ?? null) : ($meta['receiver_url'] ?? null),
            'canSign'         => $this->canSign($outgoing),
            'signLabel'       => $outgoing ? 'Подписать и отправить' : 'Подписать',
            'sbisState'       => $meta['sbis_state']['name'] ?? null,
            'error'           => $meta['error'] ?? null,
            'status'          => $this->status,
            'statusLabel'     => $this->status->label(),
            'direction'       => $outgoing ? 'outgoing' : 'incoming',
            'directionLabel'  => $outgoing ? 'Исходящий' : 'Входящий',
            'organization'    => $this->organization?->name ?? '—',
            'counterparty'    => $outgoing
                ? ($this->counterparty?->name ?? '—')
                : ($this->organization?->name ?? '—'),
            'request'         => $repairRequest === null ? null : [
                'id'     => $repairRequest->uuid,
                'number' => $repairRequest->number,
            ],
            'expectedAction'  => $action,
            'actionOwner'     => $actionOwner,
            'source'          => [
                'type' => $this->source_type,
                'id'   => $this->source_id,
            ],
            'sentAt'          => $this->sent_at?->toISOString(),
            'deliveredAt'     => $this->delivered_at?->toISOString(),
            'signedAt'        => $this->signed_at?->toISOString(),
            'lastSyncedAt'    => $this->last_synced_at?->toISOString(),
            'canRetry'        => in_array($this->status, [EdoDocumentStatus::Rejected, EdoDocumentStatus::Unknown], true),
            'canDownload'     => $this->payload_attachment_id !== null,
            'pdfUrl'          => $this->payload_attachment_id && $this->payloadAttachment
                ? "/api/attachments/{$this->payloadAttachment->uuid}/download"
                : null,
        ];
    }

    /** Подписать УКЕП из реестра можно документ СБИС, ждущий действия этой стороны. */
    private function canSign(bool $outgoing): bool
    {
        if ($this->provider !== EdoProvider::Sbis || $this->external_id === null) {
            return false;
        }

        $statuses = $outgoing
            ? [EdoDocumentStatus::Draft, EdoDocumentStatus::ToSign, EdoDocumentStatus::Prepared]
            : [EdoDocumentStatus::Sended, EdoDocumentStatus::Delivered, EdoDocumentStatus::Received, EdoDocumentStatus::OnAgreement];

        return in_array($this->status, $statuses, true);
    }

    /**
     * Какое действие ожидается по документу и с чьей стороны.
     * actionOwner: me — нужно действие этой организации, counterparty — ждём
     * контрагента, operator — ждём оператора ЭДО, null — действий нет.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function expectedAction(EdoDocumentStatus $status, bool $outgoing): array
    {
        return match ($status) {
            EdoDocumentStatus::Draft,
            EdoDocumentStatus::ToSign,
            EdoDocumentStatus::Prepared => $outgoing
                ? ['Подписать и отправить получателю', 'me']
                : ['Ожидается подпись отправителя', 'counterparty'],
            EdoDocumentStatus::WaitingForSend => ['Отправляется оператором ЭДО', 'operator'],
            EdoDocumentStatus::Sended,
            EdoDocumentStatus::Delivered,
            EdoDocumentStatus::Received,
            EdoDocumentStatus::OnAgreement => $outgoing
                ? ['Ожидается подпись получателя', 'counterparty']
                : ['Подписать (получатель)', 'me'],
            EdoDocumentStatus::ClarificationRequested => $outgoing
                ? ['Исправить по уточнению и отправить снова', 'me']
                : ['Ожидается исправленный документ', 'counterparty'],
            EdoDocumentStatus::Rejected => $outgoing
                ? ['Исправить и отправить повторно', 'me']
                : ['Ожидается новый документ', 'counterparty'],
            EdoDocumentStatus::RequestedAvoidance => ['Ответить на аннулирование', 'me'],
            EdoDocumentStatus::OnRevocation => ['Ожидается подтверждение отзыва', 'counterparty'],
            EdoDocumentStatus::Unknown => ['Обновить статус у оператора', 'me'],
            EdoDocumentStatus::Signed,
            EdoDocumentStatus::Accepted,
            EdoDocumentStatus::Revoked,
            EdoDocumentStatus::RejectedAvoidance,
            EdoDocumentStatus::AcceptedAvoidance => ['Действий не требуется', null],
        };
    }
}
