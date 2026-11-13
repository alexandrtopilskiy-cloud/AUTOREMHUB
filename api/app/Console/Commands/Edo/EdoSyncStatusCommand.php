<?php

declare(strict_types=1);

namespace App\Console\Commands\Edo;

use App\Models\EdoDocument;
use App\Services\Edo\EdoSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EdoSyncStatusCommand extends Command
{
    protected $signature = 'edo:sync-status';
    protected $description = 'Синхронизация статусов документов из СБИС';

    public function handle(EdoSyncService $syncService): int
    {
        $logger = Log::channel('edo');

        $documents = EdoDocument::whereNotNull('external_id')
        ->whereNotIn('status', ['accepted', 'rejected', 'revoked'])
            ->get();

        if ($documents->isEmpty()) {
            $this->info("Нет документов для синхронизации.");
            return self::SUCCESS;
        }

        $grouped = $documents->groupBy('source_id');

        $logger->info("=== СТАРТ СИНХРОНИЗАЦИИ ===", [
            'total_documents' => $documents->count(),
            'total_requests'  => $grouped->count(),
        ]);

        foreach ($grouped as $requestId => $requestDocuments) {
            // Если source_id пустой
            if (empty($requestId)) {
                $requestNumber = "Без привязки к заявке";
            } else {
                $requestNumber = $requestDocuments->first()->source?->number ?? "ID: {$requestId}";
            }

            $this->newLine();
            $this->info("📄 {$requestNumber} (документов: {$requestDocuments->count()})");
            $logger->info("--- {$requestNumber} ---", [
                'request_id' => $requestId ?: null,
                'documents_count' => $requestDocuments->count(),
            ]);

            foreach ($requestDocuments as $document) {
                $this->line("  • {$document->number} ({$document->document_type->value})");
                $syncService->syncDocument($document);
            }
        }

        $this->newLine();
        $this->info("✅ Синхронизация завершена!");
        $logger->info("=== КОНЕЦ СИНХРОНИЗАЦИИ ===");

        return self::SUCCESS;
    }
}
