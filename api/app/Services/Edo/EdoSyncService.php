<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoProvider;
use App\Models\EdoDocument;
use App\Services\Edo\Drivers\Diadoc\DiadocProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class EdoSyncService
{
    private const CACHE_KEY_LAST_EVENT = 'edo:diadoc:last_event_id';

    public function __construct(
        private readonly DiadocProvider $diadocProvider,
    ) {}

    /**
     * Основной метод синхронизации.
     */
    public function sync(): void
    {
        if (config('edo.mock')) {
            Log::channel('edo')->info('MOCK DIADOC: Синхронизация статусов пропущена (режим отладки)');
            return;
        }

        $lastEventId = (int) Cache::get(self::CACHE_KEY_LAST_EVENT, 0);

        try {
            $result = $this->diadocProvider->pullEvents($lastEventId, 100);

            foreach ($result->events as $event) {
                $this->handleEvent($event);
            }

            if ($result->lastEventId > $lastEventId) {
                Cache::forever(self::CACHE_KEY_LAST_EVENT, $result->lastEventId);
            }
        } catch (\Throwable $e) {
            Log::channel('edo')->error('Ошибка синхронизации с Диадоком: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Обработка одного события от Диадока.
     */
    private function handleEvent(array $event): void
    {
        $eventType = $event['EventType'] ?? null;
        $entityId = $event['DocumentId'] ?? null;

        if (!$entityId) {
            return;
        }

        // Ищем документ в нашей БД по EntityId (мы сохраняли его в meta при отправке)
        $document = EdoDocument::query()
            ->where('provider', EdoProvider::Diadoc->value)
            ->whereJsonContains('meta->diadoc_entity_id', $entityId)
            ->first();

        if (!$document) {
            Log::channel('edo')->warning("Документ с EntityId={$entityId} не найден в БД");
            return;
        }

        // Мапим события Диадока в наши статусы
        $newStatus = match ($eventType) {
            'MessageDelivered'          => EdoDocumentStatus::Delivered,
            'MessageReceived'           => EdoDocumentStatus::Received,
            'DocumentSignedByRecipient' => EdoDocumentStatus::Signed,
            'DocumentRejected'          => EdoDocumentStatus::Rejected,
            default                     => null,
        };

        if ($newStatus) {
            $document->update([
                'status' => $newStatus,
                'last_synced_at' => now(),
            ]);

            Log::channel('edo')->info("Статус документа {$document->uuid} обновлен на {$newStatus->value}");
        }
    }
}
