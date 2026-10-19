<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->nullable()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('source_request_id')->nullable()->constrained('repair_requests')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vin', 17);
            $table->unsignedInteger('revision')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'vehicle_id']);
            $table->comment('App\\Models\\Recommendation — отдельный документ рекомендаций по VIN');
        });
        Schema::create('recommendation_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('recommendation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->jsonb('snapshot');
            $table->jsonb('display');
            $table->string('actor_label');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_request_id')->nullable()->constrained('repair_requests')->nullOnDelete();
            $table->timestampTz('moved_at')->nullable();
            $table->timestampsTz();
            $table->comment('App\\Models\\RecommendationItem — неизменяемый снимок работы/ЗЧ и история переноса');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_items');
        Schema::dropIfExists('recommendations');
    }
};
