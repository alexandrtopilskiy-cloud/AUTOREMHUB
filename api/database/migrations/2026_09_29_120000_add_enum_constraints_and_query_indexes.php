<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ограничения для enum-полей, которых ещё нет в исходной схеме.
     *
     * Значения намеренно зафиксированы в миграции: последующее изменение
     * PHP-enum не должно неявно менять уже применённую схему PostgreSQL.
     *
     * @var array<string, string>
     */
    private const CONSTRAINTS = [
        'request_parts_status_check' =>
            "ALTER TABLE request_parts ADD CONSTRAINT request_parts_status_check "
            . "CHECK (status IN ('proposed', 'approved', 'ordered', 'received', "
            . "'installed', 'returned', 'exchanged'))",
        'part_movements_kind_check' =>
            "ALTER TABLE part_movements ADD CONSTRAINT part_movements_kind_check "
            . "CHECK (kind IN ('return', 'exchange'))",
        'attachments_purpose_check' =>
            "ALTER TABLE attachments ADD CONSTRAINT attachments_purpose_check "
            . "CHECK (purpose IN ('evidence', 'diagnostic', 'issued_invoice', "
            . "'paid_invoice', 'acceptance_act'))",
        'attachments_mime_type_check' =>
            "ALTER TABLE attachments ADD CONSTRAINT attachments_mime_type_check "
            . "CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp', "
            . "'video/mp4', 'video/webm', 'video/quicktime', 'application/pdf'))",
        'attachments_size_check' =>
            'ALTER TABLE attachments ADD CONSTRAINT attachments_size_check CHECK (size > 0)',
        'invoices_status_check' =>
            "ALTER TABLE invoices ADD CONSTRAINT invoices_status_check "
            . "CHECK (status IN ('issued', 'submitted', 'paid'))",
        'work_order_items_kind_check' =>
            "ALTER TABLE work_order_items ADD CONSTRAINT work_order_items_kind_check "
            . "CHECK (kind IN ('work', 'part'))",
        'work_order_items_quantity_positive_check' =>
            'ALTER TABLE work_order_items '
            . 'ADD CONSTRAINT work_order_items_quantity_positive_check '
            . 'CHECK (quantity > 0)',
        'request_events_stage_check' =>
            'ALTER TABLE request_events ADD CONSTRAINT request_events_stage_check '
            . 'CHECK (stage BETWEEN 0 AND 11)',
        'request_status_periods_stage_check' =>
            'ALTER TABLE request_status_periods '
            . 'ADD CONSTRAINT request_status_periods_stage_check '
            . 'CHECK (stage BETWEEN 0 AND 11)',
        'request_approvals_stage_check' =>
            'ALTER TABLE request_approvals '
            . 'ADD CONSTRAINT request_approvals_stage_check '
            . 'CHECK (stage BETWEEN 0 AND 11)',
        'request_chat_messages_sender_role_check' =>
            'ALTER TABLE request_chat_messages '
            . 'ADD CONSTRAINT request_chat_messages_sender_role_check '
            . "CHECK (sender_role IN ('customer', 'station', 'manager'))",
    ];

    /**
     * Индексы по внешним ключам и фактическим условиям выборок API.
     *
     * @var array<string, string>
     */
    private const INDEXES = [
        'repair_requests_created_by_index' =>
            'CREATE INDEX IF NOT EXISTS repair_requests_created_by_index '
            . 'ON repair_requests (created_by)',
        'repair_requests_tenant_stage_index' =>
            'CREATE INDEX IF NOT EXISTS repair_requests_tenant_stage_index '
            . 'ON repair_requests (tenant_id, stage)',
        'request_events_request_occurred_index' =>
            'CREATE INDEX IF NOT EXISTS request_events_request_occurred_index '
            . 'ON request_events (repair_request_id, occurred_at DESC)',
        'request_status_periods_request_entered_index' =>
            'CREATE INDEX IF NOT EXISTS request_status_periods_request_entered_index '
            . 'ON request_status_periods (repair_request_id, entered_at DESC)',
        'attachments_repair_request_id_index' =>
            'CREATE INDEX IF NOT EXISTS attachments_repair_request_id_index '
            . 'ON attachments (repair_request_id)',
        'attachments_status_period_index' =>
            'CREATE INDEX IF NOT EXISTS attachments_status_period_index '
            . 'ON attachments (request_status_period_id)',
        'attachments_request_event_id_index' =>
            'CREATE INDEX IF NOT EXISTS attachments_request_event_id_index '
            . 'ON attachments (request_event_id)',
        'diagnostic_reports_repair_request_id_index' =>
            'CREATE INDEX IF NOT EXISTS diagnostic_reports_repair_request_id_index '
            . 'ON diagnostic_reports (repair_request_id)',
        'request_works_repair_request_id_index' =>
            'CREATE INDEX IF NOT EXISTS request_works_repair_request_id_index '
            . 'ON request_works (repair_request_id)',
        'request_parts_repair_request_id_index' =>
            'CREATE INDEX IF NOT EXISTS request_parts_repair_request_id_index '
            . 'ON request_parts (repair_request_id)',
        'request_parts_supplier_id_index' =>
            'CREATE INDEX IF NOT EXISTS request_parts_supplier_id_index '
            . 'ON request_parts (supplier_id)',
        'request_parts_catalog_part_id_index' =>
            'CREATE INDEX IF NOT EXISTS request_parts_catalog_part_id_index '
            . 'ON request_parts (catalog_part_id)',
        'part_movements_request_part_id_index' =>
            'CREATE INDEX IF NOT EXISTS part_movements_request_part_id_index '
            . 'ON part_movements (request_part_id)',
        'request_approvals_request_stage_decision_index' =>
            'CREATE INDEX IF NOT EXISTS request_approvals_request_stage_decision_index '
            . 'ON request_approvals (repair_request_id, stage, decision)',
        'request_chat_files_message_index' =>
            'CREATE INDEX IF NOT EXISTS request_chat_files_message_index '
            . 'ON request_chat_files (request_chat_message_id)',
        'work_order_items_order_kind_index' =>
            'CREATE INDEX IF NOT EXISTS work_order_items_order_kind_index '
            . 'ON work_order_items (work_order_id, kind)',
        'vehicle_service_records_vehicle_performed_index' =>
            'CREATE INDEX IF NOT EXISTS vehicle_service_records_vehicle_performed_index '
            . 'ON vehicle_service_records (vehicle_id, performed_at DESC)',
        'vehicles_tenant_organization_active_index' =>
            'CREATE INDEX IF NOT EXISTS vehicles_tenant_organization_active_index '
            . 'ON vehicles (tenant_id, organization_id, active)',
        'service_reasons_tenant_active_index' =>
            'CREATE INDEX IF NOT EXISTS service_reasons_tenant_active_index '
            . 'ON service_reasons (tenant_id, active)',
        'service_reasons_parent_id_index' =>
            'CREATE INDEX IF NOT EXISTS service_reasons_parent_id_index '
            . 'ON service_reasons (parent_id)',
        'work_types_tenant_active_index' =>
            'CREATE INDEX IF NOT EXISTS work_types_tenant_active_index '
            . 'ON work_types (tenant_id, active)',
        'suppliers_tenant_active_index' =>
            'CREATE INDEX IF NOT EXISTS suppliers_tenant_active_index '
            . 'ON suppliers (tenant_id, active)',
        'stations_tenant_active_index' =>
            'CREATE INDEX IF NOT EXISTS stations_tenant_active_index '
            . 'ON stations (tenant_id, active)',
        'catalog_parts_tenant_active_index' =>
            'CREATE INDEX IF NOT EXISTS catalog_parts_tenant_active_index '
            . 'ON catalog_parts (tenant_id, active)',
    ];

    public function up(): void
    {
        foreach (self::CONSTRAINTS as $name => $sql) {
            if (!$this->constraintExists($this->constraintTable($name), $name)) {
                DB::statement($sql);
            }
        }

        foreach (self::INDEXES as $sql) {
            DB::statement($sql);
        }

        $this->addEnumComments();
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }

        foreach (array_reverse(array_keys(self::CONSTRAINTS)) as $name) {
            $table = $this->constraintTable($name);

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 '
            . 'FROM pg_constraint AS constraint_record '
            . 'JOIN pg_class AS table_record '
            . 'ON table_record.oid = constraint_record.conrelid '
            . 'JOIN pg_namespace AS schema_record '
            . 'ON schema_record.oid = table_record.relnamespace '
            . 'WHERE schema_record.nspname = current_schema() '
            . 'AND table_record.relname = ? '
            . 'AND constraint_record.conname = ? '
            . 'LIMIT 1',
            [$table, $name],
        ) !== null;
    }

    private function constraintTable(string $name): string
    {
        return match ($name) {
            'request_parts_status_check' => 'request_parts',
            'part_movements_kind_check' => 'part_movements',
            'attachments_purpose_check',
            'attachments_mime_type_check',
            'attachments_size_check' => 'attachments',
            'invoices_status_check' => 'invoices',
            'work_order_items_kind_check',
            'work_order_items_quantity_positive_check' => 'work_order_items',
            'request_events_stage_check' => 'request_events',
            'request_status_periods_stage_check' => 'request_status_periods',
            'request_approvals_stage_check' => 'request_approvals',
            'request_chat_messages_sender_role_check' => 'request_chat_messages',
        };
    }

    private function addEnumComments(): void
    {
        $comments = [
            ['users', 'role', 'Роль пользователя; см. App\\Enums\\UserRole'],
            ['organizations', 'kind', 'Тип организации; см. App\\Enums\\OrganizationKind'],
            ['repair_requests', 'stage', 'Этап заявки 0..11; см. App\\Enums\\RepairStage'],
            ['request_events', 'stage', 'Этап заявки 0..11; см. App\\Enums\\RepairStage'],
            ['request_status_periods', 'stage', 'Этап заявки 0..11; см. App\\Enums\\RepairStage'],
            ['organization_people', 'kind', 'Тип лица; см. App\\Enums\\PersonKind'],
            ['vehicle_contacts', 'kind', 'Тип связи с ТС; см. App\\Enums\\PersonKind'],
            ['request_parts', 'status', 'Статус запчасти; см. App\\Enums\\RequestPartStatus'],
            ['part_movements', 'kind', 'Тип движения; см. App\\Enums\\PartMovementKind'],
            ['attachments', 'purpose', 'Назначение файла; см. App\\Enums\\AttachmentPurpose'],
            ['attachments', 'mime_type', 'MIME-тип файла; см. App\\Enums\\AttachmentMime'],
            ['invoices', 'status', 'Статус счёта; см. App\\Enums\\InvoiceStatus'],
            ['request_approvals', 'stage', 'Этап заявки 0..11; см. App\\Enums\\RepairStage'],
            ['request_approvals', 'decision', 'Решение; см. App\\Enums\\ApprovalDecision'],
            ['request_chat_messages', 'sender_role', 'Роль автора; см. App\\Enums\\UserRole'],
            ['work_order_items', 'kind', 'Тип позиции; см. App\\Enums\\WorkOrderItemKind'],
        ];

        foreach ($comments as [$table, $column, $comment]) {
            DB::statement(sprintf(
                'COMMENT ON COLUMN %s.%s IS %s',
                $table,
                $column,
                DB::getPdo()->quote($comment),
            ));
        }
    }
};
