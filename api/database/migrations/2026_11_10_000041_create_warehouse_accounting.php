<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Складской учёт (ТЗ Alex от 10.11.2026): склады организации, наценка на
 * товары, документы Приход (PRH-) и Реализация (RLZ-), регистр движений ЗЧ.
 * Склад ведёт организация пользователя (УК или СТО). Справочник запчастей
 * больше не хранит поставщика и цены — они берутся из документов Прихода.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->timestampsTz();
            $table->index(['organization_id', 'name']);
        });

        Schema::create('warehouse_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('markup_mode', 20)->default('flat');
            $table->decimal('markup_percent', 7, 2)->default(0);
            $table->jsonb('markup_tiers')->default('[]');
            $table->timestampsTz();
        });

        Schema::create('stock_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('sequence');
            $table->string('number', 30);
            $table->string('status', 20)->default('created');
            $table->date('document_date');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'type', 'sequence']);
            $table->index(['organization_id', 'type', 'status']);
        });

        Schema::create('stock_document_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('catalog_part_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['catalog_part_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_part_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestampTz('occurred_at');
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_id', 'catalog_part_id', 'occurred_at']);
            $table->index(['warehouse_id', 'catalog_part_id']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_check CHECK (type IN ('receipt', 'sale'))");
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_status_check CHECK (status IN ('created', 'posted'))");
        DB::statement("ALTER TABLE warehouse_settings ADD CONSTRAINT warehouse_settings_mode_check CHECK (markup_mode IN ('flat', 'progressive'))");
        DB::statement("ALTER TABLE stock_document_items ADD CONSTRAINT stock_document_items_quantity_check CHECK (quantity > 0)");
        DB::statement("COMMENT ON TABLE stock_movements IS 'Регистр движений ЗЧ по складам: + приход, − расход (Реализация или ЗР на этапе «ТС готово к выдаче»)'");

        // Справочник запчастей: поставщик и цены переносятся в документы Прихода.
        Schema::table('catalog_parts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['purchase_price', 'sale_price']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_parts', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
        });
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_document_items');
        Schema::dropIfExists('stock_documents');
        Schema::dropIfExists('warehouse_settings');
        Schema::dropIfExists('warehouses');
    }
};
