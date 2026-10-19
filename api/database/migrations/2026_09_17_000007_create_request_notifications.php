<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('request_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 50);
            $table->string('title');
            $table->text('message');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'read_at']);
            $table->unique(
                ['user_id', 'request_event_id', 'kind'],
                'request_notifications_event_unique',
            );
        });

        DB::statement(
            "COMMENT ON TABLE request_notifications IS "
            . "'App\\Models\\RequestNotification — внутренние уведомления "
            . "пользователей по заявкам'",
        );
        DB::statement(
            "COMMENT ON COLUMN request_notifications.kind IS "
            . "'Машинный тип уведомления, например work_started'",
        );
        DB::statement(
            "COMMENT ON COLUMN request_notifications.read_at IS "
            . "'Дата прочтения уведомления конкретным пользователем'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('request_notifications');
    }
};
