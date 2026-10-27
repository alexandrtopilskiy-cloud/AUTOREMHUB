<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Снимаем CHECK-constraint'ы с attachments.purpose и attachments.mime_type.
     *
     * Валидация значений остаётся на уровне PHP: AttachmentPurpose::from()
     * и AttachmentMime::tryFrom() вызываются при каждом создании Attachment
     * (см. AttachmentController::store и EdoService::persistPayloadAsAttachment).
     *
     * Причина: список допустимых значений расширяется чаще, чем меняется БД
     * (ЭДО добавляет edo_payload, будущие сценарии могут добавить ещё что-то),
     * и каждый раз писать миграцию ради одного значения нецелесообразно.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_purpose_check');
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_mime_type_check');
    }

    /**
     * Восстанавливаем constraint'ы в исходном виде (по состоянию
     * на 2026_09_29_120000_add_enum_constraints_and_query_indexes.php).
     *
     * Если к моменту отката в БД уже есть записи с purpose=edo_payload
     * или с mime_type IN ('text/xml','application/xml'), откат упадёт.
     * Это сознательно: down() должен возвращать схему в исходное состояние,
     * а не молча терять данные.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE attachments ADD CONSTRAINT attachments_purpose_check "
            . "CHECK (purpose IN ("
            . "'evidence', 'diagnostic', 'issued_invoice', "
            . "'paid_invoice', 'acceptance_act'"
            . "))"
        );

        DB::statement(
            "ALTER TABLE attachments ADD CONSTRAINT attachments_mime_type_check "
            . "CHECK (mime_type IN ("
            . "'image/jpeg', 'image/png', 'image/webp', "
            . "'video/mp4', 'video/webm', 'video/quicktime', "
            . "'application/pdf'"
            . "))"
        );
    }
};