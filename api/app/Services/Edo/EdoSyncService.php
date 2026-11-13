<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Models\EdoDocument;
use App\Services\Edo\Drivers\Sbis\SbisClientFactory;
use Illuminate\Support\Facades\Log;

final class EdoSyncService
{
    public function __construct(
        private readonly SbisClientFactory $clientFactory
    ) {}

    public function syncDocument(EdoDocument $document): void
    {
        $logger = Log::channel('edo');

        // 1. Пропускаем мок-документы (их нет в реальном СБИС)
        if (str_starts_with($document->external_id, 'mock-') || str_starts_with($document->external_id, 'MOCK-')) {
            $logger->info("  [MOCK] Пропуск запроса к СБИС (документ создан в мок-режиме)", [
                'document' => $document->number,
            ]);
            return;
        }

        $sender = $document->organization;
        if (!$sender) {
            $logger->warning("  [WARN] Нет организации у документа", [
                'document' => $document->number,
            ]);
            return;
        }

        // 2. Если у организации нет токена — не пытаемся логиниться, просто пропускаем
        if (empty($sender->edo_token) && !config('edo.mock')) {
            $logger->warning("  [WARN] Нет edo_token у организации {$sender->name}. Синхронизация невозможна.", [
                'document' => $document->number,
            ]);
            return;
        }

        $client = $this->clientFactory->make($sender);

        try {
            // 3. Запрос в СБИС
            $sbisData = $client->readDocument($document->external_id);

            // Парсим статус
            $statusCode = $sbisData['Состояние']['Код'] ?? null;
            $statusName = $this->mapSbisStatus($statusCode);
            $statusText = $sbisData['Состояние']['Название'] ?? 'Нет статуса';

            // Парсим редакцию
            $revisions = $sbisData['Редакция'] ?? [];
            $lastRevision = end($revisions);
            $revisionId = $lastRevision['Идентификатор'] ?? null;

            // Парсим подписанта
            $signerName = null;
            if (!empty($sbisData['Подпись'])) {
                $signerName = $sbisData['Подпись'][0]['Сертификат']['ФИО'] ?? null;
            }

            // Логируем успех
            $logger->info("  [OK] Документ синхронизирован", [
                'document'     => $document->number,
                'status_code'  => $statusCode,
                'status_text'  => $statusText,
                'status_local' => $statusName,
                'signer'       => $signerName,
                'revision_id'  => $revisionId,
            ]);

            // Обновляем БД
            $document->update([
                'status' => $statusName,
                'meta' => array_merge($document->meta ?? [], [
                    'sbis_status_code' => $statusCode,
                    'sbis_status_name' => $statusText,
                    'sbis_signer'      => $signerName,
                    'sbis_revision'    => $revisionId,
                    'sbis_last_sync'   => now()->toIso8601String(),
                ]),
            ]);

        } catch (\Throwable $e) {
            $logger->error("  [ERROR] Ошибка синхронизации", [
                'document' => $document->number,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    private function mapSbisStatus(?string $code): string
    {
        return match ($code) {
            '0'  => 'draft',     // Документ редактируется (черновик)
            '1'  => 'draft',     // Черновик
            '7'  => 'accepted',  // Подписан и возвращен
            '9'  => 'rejected',  // Отклонен/запрошено уточнение
            '10' => 'sended',    // В обработке
            '23' => 'to_sign',   // Ожидает подписания
            default => 'unknown',
        };
    }
}
