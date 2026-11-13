<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Индексы на внешние ключи, по которым PostgreSQL проверяет ссылки при удалении
 * ЗР, её событий, вложений и складских документов. Без них каждая удаляемая
 * строка вызывала полный просмотр связанной таблицы, и массовое удаление ЗР
 * в меню «Разработчик» не укладывалось в таймаут запроса.
 */
return new class extends Migration
{
    private const INDEXES = [
        'edo_documents_payload_attachment_id_index' => ['edo_documents', 'payload_attachment_id'],
        'edo_documents_signed_attachment_id_index' => ['edo_documents', 'signed_attachment_id'],
        'edo_documents_acceptance_attachment_id_index' => ['edo_documents', 'acceptance_attachment_id'],
        'edo_documents_source_index' => ['edo_documents', 'source_type, source_id'],
        'invoices_document_attachment_id_index' => ['invoices', 'document_attachment_id'],
        'invoices_paid_document_attachment_id_index' => ['invoices', 'paid_document_attachment_id'],
        'request_documents_attachment_id_index' => ['request_documents', 'attachment_id'],
        'request_documents_signed_attachment_id_index' => ['request_documents', 'signed_attachment_id'],
        'request_approvals_request_event_id_index' => ['request_approvals', 'request_event_id'],
        'request_notifications_repair_request_id_index' => ['request_notifications', 'repair_request_id'],
        'request_notifications_request_event_id_index' => ['request_notifications', 'request_event_id'],
        'recommendation_items_recommendation_id_index' => ['recommendation_items', 'recommendation_id'],
        'recommendation_items_source_request_id_index' => ['recommendation_items', 'source_request_id'],
        'recommendation_items_target_request_id_index' => ['recommendation_items', 'target_request_id'],
        'recommendations_source_request_id_index' => ['recommendations', 'source_request_id'],
        'repair_requests_parts_transfer_document_id_index' => ['repair_requests', 'parts_transfer_document_id'],
        'stock_documents_repair_request_id_index' => ['stock_documents', 'repair_request_id'],
        'stock_document_items_stock_document_id_index' => ['stock_document_items', 'stock_document_id'],
        'stock_movements_source_index' => ['stock_movements', 'source_type, source_id'],
        'vehicle_service_records_repair_request_id_index' => ['vehicle_service_records', 'repair_request_id'],
        'work_order_versions_work_order_id_index' => ['work_order_versions', 'work_order_id'],
        'part_movements_replacement_part_id_index' => ['part_movements', 'replacement_part_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
