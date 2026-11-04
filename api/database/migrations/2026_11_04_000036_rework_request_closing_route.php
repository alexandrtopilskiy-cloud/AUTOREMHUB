<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Новый маршрут закрытия ЗР (04.11.2026):
 * 5 В работе → 6 ТС готово к выдаче Заказчику (СТО) → 7 Выдан. Подготовка
 * документов (УК) → 8 Подтверждение оплаты Заказчика (УК) → 9 Оплата в СТО
 * (УК) → 10 Заявка на ремонт закрыта.
 *
 * Старые этапы: 6 Готово, 7 Выдача автомобиля, 8 Закрывающие документы,
 * 9 Ожидает оплаты, 10 Оплата на проверке УК, 11 Заявка закрыта.
 * Соответствие: 6,7 → 6; 8 → 7; 9,10 → 8; 11 → 10.
 */
return new class extends Migration
{
    private const TABLES = [
        'repair_requests' => 'request_stage',
        'request_status_periods' => 'request_status_periods_stage_check',
        'request_events' => 'request_events_stage_check',
        'request_approvals' => 'request_approvals_stage_check',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "UPDATE {$table} SET stage = CASE stage "
                . 'WHEN 7 THEN 6 WHEN 8 THEN 7 WHEN 9 THEN 8 WHEN 10 THEN 8 WHEN 11 THEN 10 '
                . 'ELSE stage END WHERE stage >= 7',
            );
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (stage BETWEEN 0 AND 10)");
        }

        Schema::create('request_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('repair_request_id')->constrained('repair_requests')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('number', 40);
            $table->date('document_date');
            $table->foreignId('seller_organization_id')->nullable()->constrained('organizations');
            $table->foreignId('buyer_organization_id')->nullable()->constrained('organizations');
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->jsonb('payload');
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('signed_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->boolean('driver_documents_verified')->default(false);
            $table->timestampTz('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users');
            $table->decimal('paid_amount', 14, 2)->nullable();
            $table->date('paid_at')->nullable();
            $table->text('paid_comment')->nullable();
            $table->timestampTz('paid_marked_at')->nullable();
            $table->foreignId('paid_marked_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['repair_request_id', 'type']);
            $table->index(['tenant_id', 'type']);
        });

        DB::statement(
            "ALTER TABLE request_documents ADD CONSTRAINT request_documents_type_check CHECK (type IN ("
            . "'transfer_act', 'station_work_order', 'station_upd', 'station_invoice', "
            . "'customer_work_order', 'customer_upd', 'customer_invoice', 'agent_report'))",
        );
        DB::statement("COMMENT ON TABLE request_documents IS 'Документы закрытия ЗР: акт передачи ТС, ЗН/УПД/счёт СТО→УК и УК→Заказчик, отчёт агента'");
    }

    public function down(): void
    {
        Schema::dropIfExists('request_documents');

        foreach (self::TABLES as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "UPDATE {$table} SET stage = CASE stage "
                . 'WHEN 10 THEN 11 WHEN 9 THEN 10 WHEN 8 THEN 9 WHEN 7 THEN 8 '
                . 'ELSE stage END WHERE stage >= 7',
            );
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (stage BETWEEN 0 AND 11)");
        }
    }
};
