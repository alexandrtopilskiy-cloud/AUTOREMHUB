<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edo_documents', function (Blueprint $table): void {
            // XML, сгенерированный оператором, который мы отправили на подпись.
            $table->foreignId('payload_attachment_id')
                ->nullable()
                ->after('number')
                ->constrained('attachments')
                ->nullOnDelete();

            // Подписанный XML (или PDF печатной формы), скачанный после подписания.
            $table->foreignId('signed_attachment_id')
                ->nullable()
                ->after('payload_attachment_id')
                ->constrained('attachments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('edo_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payload_attachment_id');
            $table->dropConstrainedForeignId('signed_attachment_id');
        });
    }
};
