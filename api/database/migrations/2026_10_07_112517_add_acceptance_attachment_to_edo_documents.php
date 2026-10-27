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
            $table->unsignedBigInteger('acceptance_attachment_id')
                ->nullable()
                ->after('signed_attachment_id');

            $table->foreign('acceptance_attachment_id')
                ->references('id')
                ->on('attachments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('edo_documents', function (Blueprint $table): void {
            $table->dropForeign(['acceptance_attachment_id']);
            $table->dropColumn('acceptance_attachment_id');
        });
    }
};
