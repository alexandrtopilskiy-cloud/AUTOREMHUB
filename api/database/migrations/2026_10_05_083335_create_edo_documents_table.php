<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edo_documents', function (Blueprint $table): void {
            $table->id();

            // Мультитенантность — как у всех Entity в проекте
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            // Составной FK: (id, tenant_id) → organizations (id, tenant_id).
            // Такой же паттерн используется в catalog_records и repair_requests —
            // защищает от случайного кросс-тенантного мэппинга.
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('counterparty_id')->nullable();

            // Тип документа, статус, оператор (см. App\Enums\Edo\*)
            $table->string('document_type', 32);
            $table->string('status', 32)->default('draft');
            $table->string('provider', 32);

            // Внешние идентификаторы в ЭДО-операторе
            $table->string('external_id', 100)->nullable();
            $table->string('external_uuid', 64)->nullable();

            // Реквизиты документа
            $table->string('number', 100);
            $table->date('document_date');

            // Связь с исходной бизнес-сущностью (RepairRequest, WorkOrder, Invoice, ...)
            $table->nullableMorphs('source');

            // Универсальный контейнер для метаданных ЭДО:
            //   - сырой ответ API
            //   - attachment_uuid'ы (ссылки на скачанные PDF/XML)
            //   - edi_code получателя, sender/receiver name на момент отправки
            //   - error_details при ошибках
            $table->jsonb('meta')->default('{}');

            // Аудит
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();

            $table->timestampsTz();

            // Уникальность uuid для роутинга /api/edo/documents/{uuid}
            $table->uuid('uuid')->unique();

            // Составные FK на organizations (id, tenant_id)
            $table->foreign(['organization_id', 'tenant_id'])
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['counterparty_id', 'tenant_id'])
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();

            // Индексы под фактические запросы
            $table->unique(['provider', 'external_id'], 'edo_documents_provider_external_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['organization_id', 'document_type']);
            $table->index(['counterparty_id', 'status']);
            $table->index('document_date');
        });

        // Проверки на уровне БД — по паттерну проекта (см. 2026_09_29_120000_add_enum_constraints_and_query_indexes.php)
        DB::statement(
            "ALTER TABLE edo_documents ADD CONSTRAINT edo_documents_type_check "
            . "CHECK (document_type IN ("
            . "'upd_sf', 'upd_dop', 'upd_sf_dop', 'ucd_csf', 'invoice', "
            . "'act_works', 'work_order', 'contract', 'agency_contract', "
            . "'acceptance_act', 'power_of_attorney', 'non_formalized'"
            . "))"
        );

        DB::statement(
            "ALTER TABLE edo_documents ADD CONSTRAINT edo_documents_status_check "
            . "CHECK (status IN ("
            . "'draft', 'to_sign', 'prepared', 'on_agreement', 'signed', "
            . "'rejected', 'accepted', 'on_revocation', 'revoked', "
            . "'sended', 'delivered', 'received', "
            . "'requested_avoidance', 'rejected_avoidance', 'accepted_avoidance', "
            . "'clarification_requested', 'waiting_for_send', 'unknown'"
            . "))"
        );

        DB::statement(
            "ALTER TABLE edo_documents ADD CONSTRAINT edo_documents_provider_check "
            . "CHECK (provider IN ('none', 'sberkorus', 'diadoc', 'sbis', 'taxcom', 'edoru'))"
        );

        DB::statement(
            "ALTER TABLE edo_documents ADD CONSTRAINT edo_documents_meta_check "
            . "CHECK (jsonb_typeof(meta) = 'object')"
        );

        DB::statement(
            "COMMENT ON COLUMN edo_documents.meta IS "
            . DB::getPdo()->quote('Метаданные ЭДО: сырой ответ API, ссылки на Attachment, ошибки')
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('edo_documents');
    }
};