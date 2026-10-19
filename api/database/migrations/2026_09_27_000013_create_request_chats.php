<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_chats', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('repair_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('request_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_chat_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('sender_name', 255);
            $table->string('sender_role', 20);
            $table->text('body')->default('');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['request_chat_id', 'id']);
        });

        Schema::create('request_chat_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_chat_message_id')->constrained()->restrictOnDelete();
            $table->string('disk', 40);
            $table->string('object_key')->unique();
            $table->string('original_name', 200);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('request_chat_reads', function (Blueprint $table): void {
            $table->foreignId('request_chat_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestampsTz();
            $table->primary(['request_chat_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_chat_reads');
        Schema::dropIfExists('request_chat_files');
        Schema::dropIfExists('request_chat_messages');
        Schema::dropIfExists('request_chats');
    }
};
