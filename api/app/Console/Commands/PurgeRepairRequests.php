<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RepairRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PurgeRepairRequests extends Command
{
    protected $signature = 'sto:requests-purge
        {--force : Удалить без интерактивного подтверждения}
        {--force-chats : Удалить вместе с чатами (переписка и файлы будут потеряны)}';

    public function handle(): int
    {
        $hasChats = DB::table('request_chats')->exists();

        if ($hasChats && !$this->option('force-chats')) {
            $this->error('Очистка запрещена: есть заявки с чатами. Переписка и файлы должны сохраняться вместе с заявками. Используйте --force-chats.');
            return self::FAILURE;
        }

        $count = RepairRequest::query()->count();

        if (
            $count > 0
            && !$this->option('force')
            && !$this->confirm(
                'Безвозвратно удалить все заявки (' . $count . ') и зависимые документы?',
                false,
            )
        ) {
            $this->warn('Удаление отменено.');
            return self::FAILURE;
        }

        // Собираем ключи файлов чата ДО удаления записей — чтобы почистить storage
        $chatFileKeys = $hasChats
            ? DB::table('request_chat_files')->pluck('object_key')->all()
            : [];

        DB::transaction(static function () use ($hasChats): void {
            DB::table('request_notifications')
            ->whereNotNull('repair_request_id')
                ->delete();

            if ($hasChats) {
                // Чат-таблицы — дочерние удаляем первыми
                DB::table('request_chat_files')->delete();
                DB::table('request_chat_reads')->delete();
                DB::table('request_chat_messages')->delete();
                DB::table('request_chats')->delete();
            }

            foreach ([
                'vehicle_service_records',
                'work_order_versions',
                'invoices',
                'work_order_items',
                'work_orders',
                'request_approvals',
                'part_movements',
                'request_parts',
                'request_works',
                'diagnostic_reports',
                'vehicle_acceptance_acts',
                'attachments',
                'request_status_periods',
                'request_events',
                'repair_requests',
            ] as $table) {
                DB::table($table)->delete();
            }

            DB::selectOne(
                "SELECT setval(pg_get_serial_sequence('repair_requests', 'id'), 1, false)"
            );
        });

        // Чистим файлы в storage после успешной транзакции
        foreach ($chatFileKeys as $key) {
            try {
                \Illuminate\Support\Facades\Storage::disk('media')->delete($key);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info('Удалено заявок: ' . $count . '. Следующий номер: RP-000001.');
        return self::SUCCESS;
    }
}
