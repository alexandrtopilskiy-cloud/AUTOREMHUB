<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edo_provider_state', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->unsignedBigInteger('last_event_id')->default(0);
            $table->timestampTz('last_pulled_at')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edo_provider_state');
    }
};
