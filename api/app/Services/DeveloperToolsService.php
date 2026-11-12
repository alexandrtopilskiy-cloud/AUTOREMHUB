<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\RepairRequest;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Меню «Разработчик» (только ЛК УК): служебные операции над данными tenant.
 *
 * Удаление ЗР стирает заявку целиком: работы, ЗЧ, историю, согласования,
 * документы закрытия и оплаты, акты, заказ-наряд, счета, документы ЭДО,
 * чат, уведомления, блокировки, строки Рекомендаций из этой ЗР, складские
 * движения (списание ЗЧ и Перемещение «ЗЧ от УК») и файлы на диске.
 */
final class DeveloperToolsService
{
    public const MAX_DELETE = 200;

    public static function allowed(User $user): bool
    {
        return $user->role === UserRole::Manager && $user->active !== false;
    }

    public function assertAllowed(User $user): void
    {
        if (! self::allowed($user)) {
            throw new ApiException(403, 'DEVELOPER_FORBIDDEN', 'Меню «Разработчик» доступно только в ЛК УК');
        }
    }

    /**
     * Лёгкий список всех ЗР tenant для экрана удаления.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRequests(User $user): array
    {
        $this->assertAllowed($user);

        $files = DB::table('attachments')
            ->selectRaw('repair_request_id, count(*) AS cnt')
            ->groupBy('repair_request_id');
        $chatFiles = DB::table('request_chat_files AS f')
            ->join('request_chat_messages AS m', 'm.id', '=', 'f.request_chat_message_id')
            ->join('request_chats AS c', 'c.id', '=', 'm.request_chat_id')
            ->selectRaw('c.repair_request_id, count(*) AS cnt')
            ->groupBy('c.repair_request_id');

        return DB::table('repair_requests AS r')
            ->leftJoin('organizations AS o', 'o.id', '=', 'r.customer_organization_id')
            ->leftJoin('stations AS s', 's.id', '=', 'r.assigned_station_id')
            ->leftJoin('vehicles AS v', 'v.id', '=', 'r.vehicle_id')
            ->leftJoinSub($files, 'af', 'af.repair_request_id', '=', 'r.id')
            ->leftJoinSub($chatFiles, 'cf', 'cf.repair_request_id', '=', 'r.id')
            ->where('r.tenant_id', $user->tenantId())
            ->orderByDesc('r.id')
            ->get([
                'r.id', 'r.uuid', 'r.number', 'r.stage', 'r.created_at',
                'o.name AS customer', 's.name AS station', 'v.plate', 'v.name AS vehicle',
                DB::raw('coalesce(af.cnt, 0) + coalesce(cf.cnt, 0) AS files'),
            ])
            ->map(fn ($row): array => [
                'id'        => $row->uuid,
                'systemId'  => (int) $row->id,
                'number'    => $row->number ?: RepairRequest::documentNumber('ZR', (int) $row->id),
                'stage'     => (int) $row->stage,
                'customer'  => (string) ($row->customer ?? ''),
                'station'   => (string) ($row->station ?? ''),
                'vehicle'   => trim(($row->plate ?? '') . ' ' . ($row->vehicle ?? '')),
                'files'     => (int) $row->files,
                'createdAt' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->toISOString() : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Удаляет выбранные ЗР со всеми связанными записями и файлами.
     * Набор удаляется атомарно; файлы стираются после фиксации транзакции.
     *
     * @param array<int, mixed> $ids uuid ЗР
     *
     * @return array{deletedIds: array<int, string>, files: int, removed: array<string, int>}
     */
    public function deleteRequests(User $user, mixed $ids): array
    {
        $this->assertAllowed($user);

        $validator = Validator::make(['ids' => $ids], [
            'ids'   => ['required', 'array', 'list', 'min:1', 'max:' . self::MAX_DELETE],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $uuids = $validator->validated()['ids'];
        // Большие наборы ЗР (сотни файлов) не должны упираться в лимит времени PHP.
        @set_time_limit(300);

        /** @var array<int, array{disk: string, key: string}> $files */
        $files = [];
        /** @var array<int, string> $folders */
        $folders = [];
        $removed = [];

        $deleted = DB::transaction(function () use ($user, $uuids, &$files, &$folders, &$removed): array {
            $requests = DB::table('repair_requests')
                ->where('tenant_id', $user->tenantId())
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get(['id', 'uuid', 'tenant_id']);

            if ($requests->count() !== count($uuids)) {
                throw new ApiException(404, 'REQUEST_NOT_FOUND', 'Часть ЗР не найдена — обновите список');
            }

            $ids = $requests->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $requestUuids = $requests->pluck('uuid')->all();
            $count = function (string $key, int $value) use (&$removed): void {
                $removed[$key] = ($removed[$key] ?? 0) + $value;
            };

            // Файлы: вложения ЗР (фото, сканы, PDF документов, файлы ЭДО) и файлы чата.
            foreach (DB::table('attachments')->whereIn('repair_request_id', $ids)->get(['disk', 'object_key']) as $row) {
                $files[] = ['disk' => (string) $row->disk, 'key' => (string) $row->object_key];
            }
            $chatIds = DB::table('request_chats')->whereIn('repair_request_id', $ids)->pluck('id')->all();
            $messageIds = $chatIds === [] ? [] : DB::table('request_chat_messages')->whereIn('request_chat_id', $chatIds)->pluck('id')->all();
            if ($messageIds !== []) {
                foreach (DB::table('request_chat_files')->whereIn('request_chat_message_id', $messageIds)->get(['disk', 'object_key']) as $row) {
                    $files[] = ['disk' => (string) $row->disk, 'key' => (string) $row->object_key];
                }
            }
            foreach ($requests as $row) {
                $folders[] = 'documents/' . $row->uuid;
                $folders[] = 'tenant-' . $row->tenant_id . '/requests/' . $row->uuid;
            }

            // Склад: списание ЗЧ по ЗР и Перемещения «ЗЧ от УК» отменяются, остатки возвращаются.
            $count('stockMovements', DB::table('stock_movements')
                ->where('source_type', StockMovement::SOURCE_REQUEST)
                ->whereIn('source_id', $ids)
                ->delete());
            $transferIds = DB::table('stock_documents')->whereIn('repair_request_id', $ids)->pluck('id')->all();
            if ($transferIds !== []) {
                DB::table('repair_requests')->whereIn('parts_transfer_document_id', $transferIds)->update(['parts_transfer_document_id' => null]);
                $count('stockMovements', DB::table('stock_movements')
                    ->where('source_type', StockMovement::SOURCE_TRANSFER)
                    ->whereIn('source_id', $transferIds)
                    ->delete());
                $count('stockDocuments', DB::table('stock_documents')->whereIn('id', $transferIds)->delete());
            }

            // ЭДО: документы, отправленные по ЗР.
            $count('edoDocuments', DB::table('edo_documents')
                ->where('source_type', RepairRequest::class)
                ->whereIn('source_id', $ids)
                ->delete());

            // Документы закрытия (ZN/UPD/SCH/AO/APP) с оплатами, счета, акты приёма-передачи.
            $count('requestDocuments', DB::table('request_documents')->whereIn('repair_request_id', $ids)->delete());
            $count('invoices', DB::table('invoices')->whereIn('repair_request_id', $ids)->delete());
            $count('acceptanceActs', DB::table('vehicle_acceptance_acts')->whereIn('repair_request_id', $ids)->delete());

            // Заказ-наряд, его версии и история ТО по ТС.
            $count('serviceRecords', DB::table('vehicle_service_records')->whereIn('repair_request_id', $ids)->delete());
            $workOrderIds = DB::table('work_orders')->whereIn('repair_request_id', $ids)->pluck('id')->all();
            if ($workOrderIds !== []) {
                DB::table('work_order_versions')->whereIn('work_order_id', $workOrderIds)->delete();
                DB::table('work_order_items')->whereIn('work_order_id', $workOrderIds)->delete();
                $count('workOrders', DB::table('work_orders')->whereIn('id', $workOrderIds)->delete());
            }

            // Движения ЗЧ по строкам ЗР (возвраты/обмены).
            $partIds = DB::table('request_parts')->whereIn('repair_request_id', $ids)->pluck('id')->all();
            if ($partIds !== []) {
                DB::table('part_movements')
                    ->where(fn ($q) => $q->whereIn('request_part_id', $partIds)->orWhereIn('replacement_part_id', $partIds))
                    ->delete();
            }

            // Чат.
            if ($chatIds !== []) {
                if ($messageIds !== []) {
                    DB::table('request_chat_files')->whereIn('request_chat_message_id', $messageIds)->delete();
                }
                DB::table('request_chat_reads')->whereIn('request_chat_id', $chatIds)->delete();
                $count('chatMessages', DB::table('request_chat_messages')->whereIn('request_chat_id', $chatIds)->delete());
                DB::table('request_chats')->whereIn('id', $chatIds)->delete();
            }

            // Рекомендации: строки, пришедшие из этих ЗР, удаляются; строки, перенесённые
            // в эти ЗР из Рекомендаций, снова становятся доступными в Рекомендациях.
            $count('recommendationItems', DB::table('recommendation_items')->whereIn('source_request_id', $ids)->delete());
            DB::table('recommendation_items')
                ->whereIn('target_request_id', $ids)
                ->update(['target_request_id' => null, 'moved_at' => null, 'updated_at' => now()]);
            $count('recommendations', DB::table('recommendations')
                ->where('tenant_id', $user->tenantId())
                ->whereIn('source_request_id', $ids)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('recommendation_items AS i')->whereColumn('i.recommendation_id', 'recommendations.id'))
                ->delete());

            // Согласования ссылаются на события, вложения — на периоды этапов: удаляем до ЗР.
            DB::table('request_approvals')->whereIn('repair_request_id', $ids)->delete();
            $count('attachments', DB::table('attachments')->whereIn('repair_request_id', $ids)->delete());
            DB::table('request_notifications')->whereIn('repair_request_id', $ids)->delete();
            DB::table('document_locks')
                ->where('tenant_id', $user->tenantId())
                ->where('resource_type', 'request')
                ->whereIn('resource_key', $requestUuids)
                ->delete();

            // Остальное (события, периоды, работы, ЗЧ, диагностика) — каскадом.
            DB::table('repair_requests')->whereIn('id', $ids)->delete();

            return $requestUuids;
        });

        $removedFiles = 0;
        foreach ($files as $file) {
            try {
                if (Storage::disk($file['disk'])->delete($file['key'])) {
                    $removedFiles++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        foreach (array_unique(array_merge(['local', 'media'], array_column($files, 'disk'))) as $disk) {
            foreach ($folders as $folder) {
                try {
                    Storage::disk($disk)->deleteDirectory($folder);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return ['deletedIds' => array_values($deleted), 'files' => $removedFiles, 'removed' => $removed];
    }

    /**
     * Приходы и Реализации всех организаций tenant (УК и СТО) для экрана удаления.
     * Перемещения «ЗЧ от УК» здесь не показываются: они удаляются вместе со своей ЗР.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStockDocuments(User $user): array
    {
        $this->assertAllowed($user);

        $items = DB::table('stock_document_items')
            ->selectRaw('stock_document_id, count(*) AS lines, sum(quantity * purchase_price) AS purchase, sum(quantity * coalesce(sale_price, 0)) AS sale')
            ->groupBy('stock_document_id');
        $payments = DB::table('stock_document_payments')
            ->selectRaw('stock_document_id, sum(amount) AS paid, count(*) AS cnt')
            ->groupBy('stock_document_id');

        return DB::table('stock_documents AS d')
            ->join('organizations AS o', 'o.id', '=', 'd.organization_id')
            ->leftJoin('suppliers AS s', 's.id', '=', 'd.supplier_id')
            ->leftJoin('organizations AS c', 'c.id', '=', 'd.customer_organization_id')
            ->leftJoin('warehouses AS w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoinSub($items, 'i', 'i.stock_document_id', '=', 'd.id')
            ->leftJoinSub($payments, 'p', 'p.stock_document_id', '=', 'd.id')
            ->where('d.tenant_id', $user->tenantId())
            ->whereIn('d.type', [StockDocument::RECEIPT, StockDocument::SALE])
            ->orderByDesc('d.document_date')
            ->orderByDesc('d.id')
            ->get([
                'd.uuid', 'd.type', 'd.number', 'd.status', 'd.document_date', 'o.name AS organization',
                's.name AS supplier', 'c.name AS customer', 'w.name AS warehouse',
                'i.lines', 'i.purchase', 'i.sale', 'p.paid', 'p.cnt AS payments',
            ])
            ->map(fn ($row): array => [
                'id'           => $row->uuid,
                'type'         => $row->type,
                'number'       => (string) $row->number,
                'posted'       => $row->status === StockDocument::POSTED,
                'date'         => (string) $row->document_date,
                'organization' => (string) $row->organization,
                'counterparty' => (string) ($row->type === StockDocument::RECEIPT ? ($row->supplier ?? '') : ($row->customer ?? '')),
                'warehouse'    => (string) ($row->warehouse ?? ''),
                'lines'        => (int) ($row->lines ?? 0),
                'amount'       => round((float) ($row->type === StockDocument::RECEIPT ? $row->purchase : $row->sale), 2),
                'paid'         => round((float) ($row->paid ?? 0), 2),
                'payments'     => (int) ($row->payments ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Удаляет Приходы и Реализации вместе со строками, оплатами и движениями
     * по складу (проведение отменяется). Остатки пересчитываются от оставшихся
     * движений: если ЗЧ удалённого Прихода уже списаны, остаток уйдёт в минус.
     *
     * @param array<int, mixed> $ids uuid документов
     *
     * @return array{deletedIds: array<int, string>, movements: int, payments: int}
     */
    public function deleteStockDocuments(User $user, mixed $ids): array
    {
        $this->assertAllowed($user);

        $validator = Validator::make(['ids' => $ids], [
            'ids'   => ['required', 'array', 'list', 'min:1', 'max:' . self::MAX_DELETE],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $uuids = $validator->validated()['ids'];

        return DB::transaction(function () use ($user, $uuids): array {
            $docs = DB::table('stock_documents')
                ->where('tenant_id', $user->tenantId())
                ->whereIn('type', [StockDocument::RECEIPT, StockDocument::SALE])
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get(['id', 'uuid', 'type']);

            if ($docs->count() !== count($uuids)) {
                throw new ApiException(404, 'STOCK_DOCUMENT_NOT_FOUND', 'Часть документов не найдена — обновите список');
            }

            $movements = 0;
            foreach ([StockDocument::RECEIPT => StockMovement::SOURCE_RECEIPT, StockDocument::SALE => StockMovement::SOURCE_SALE] as $type => $source) {
                $ids = $docs->where('type', $type)->pluck('id')->all();
                if ($ids !== []) {
                    $movements += DB::table('stock_movements')->where('source_type', $source)->whereIn('source_id', $ids)->delete();
                }
            }
            $docIds = $docs->pluck('id')->all();
            $payments = DB::table('stock_document_payments')->whereIn('stock_document_id', $docIds)->delete();
            DB::table('stock_document_items')->whereIn('stock_document_id', $docIds)->delete();
            DB::table('document_locks')
                ->where('tenant_id', $user->tenantId())
                ->whereIn('resource_key', $docs->pluck('uuid')->all())
                ->delete();
            DB::table('stock_documents')->whereIn('id', $docIds)->delete();

            return ['deletedIds' => $docs->pluck('uuid')->values()->all(), 'movements' => $movements, 'payments' => $payments];
        });
    }

    /**
     * Непрочитанные уведомления по всем пользователям контура (УК, СТО, Заказчики).
     *
     * @return array{users: array<int, array<string, mixed>>, total: int}
     */
    public function notificationStats(User $user): array
    {
        $this->assertAllowed($user);

        $rows = DB::table('request_notifications AS n')
            ->join('users AS u', 'u.id', '=', 'n.user_id')
            ->join('organizations AS o', 'o.id', '=', 'u.organization_id')
            ->where('o.tenant_id', $user->tenantId())
            ->whereNull('n.read_at')
            ->groupBy('u.id', 'u.login', 'u.role', 'o.name')
            ->orderBy('u.role')
            ->orderBy('o.name')
            ->orderBy('u.login')
            ->get(['u.id', 'u.login', 'u.role', 'o.name AS organization', DB::raw('count(*) AS unread')]);

        $users = $rows->map(fn ($row): array => [
            'login'        => (string) $row->login,
            'role'         => (string) $row->role,
            'roleLabel'    => UserRole::tryFrom((string) $row->role)?->label() ?? (string) $row->role,
            'organization' => (string) $row->organization,
            'unread'       => (int) $row->unread,
        ])->values()->all();

        return ['users' => $users, 'total' => array_sum(array_column($users, 'unread'))];
    }

    /**
     * «Прочитать» все уведомления во всех ЛК контура. Прочитанное уведомление
     * в системе удаляется (как при открытии уведомления пользователем).
     *
     * @return array{read: int}
     */
    public function readAllNotifications(User $user): array
    {
        $this->assertAllowed($user);

        $read = DB::table('request_notifications')
            ->whereNull('read_at')
            ->whereIn('user_id', DB::table('users AS u')
                ->join('organizations AS o', 'o.id', '=', 'u.organization_id')
                ->where('o.tenant_id', $user->tenantId())
                ->select('u.id'))
            ->delete();

        return ['read' => $read];
    }
}
