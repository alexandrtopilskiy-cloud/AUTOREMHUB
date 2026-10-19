<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('legal_form', 50)->nullable();
            $table->jsonb('requisites')->nullable();
            $table->jsonb('contacts')->nullable();
            $table->foreignId('created_by_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->comment('App\\Models\\Supplier — единый справочник поставщиков контура, реквизиты и контакты');
        });
        Schema::table('catalog_parts', function (Blueprint $table): void {
            $table->foreignId('created_by_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('created_by_organization_name')->nullable();
            $table->string('name', 500)->change();
            $table->comment('App\\Models\\CatalogPart — общий справочник ЗЧ УК и СТО; автор — организация');
        });
        Schema::table('request_parts', function (Blueprint $table): void {
            $table->string('manufacturer_snapshot', 200)->nullable();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->string('name', 500)->change();
        });
        // Историческое авторство не выдумываем. Из снимка создания берётся организация автора.
        DB::statement("UPDATE catalog_parts p SET created_by_organization_id = u.organization_id,
            created_by_organization_name = o.name
            FROM catalog_change_events e JOIN users u ON u.id = e.actor_id
            JOIN organizations o ON o.id = u.organization_id
            WHERE e.catalog_kind = 'parts' AND e.action = 'create' AND e.record_uuid = p.uuid");
        DB::statement('UPDATE request_parts p SET manufacturer_snapshot = c.manufacturer,
            purchase_price = c.purchase_price FROM catalog_parts c WHERE c.id = p.catalog_part_id');
    }

    public function down(): void
    {
        Schema::table('request_parts', function (Blueprint $table): void {
            $table->dropColumn(['manufacturer_snapshot', 'purchase_price']);
        });
        Schema::table('catalog_parts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_organization_id');
            $table->dropColumn('created_by_organization_name');
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_organization_id');
            $table->dropColumn(['legal_form', 'requisites', 'contacts']);
        });
    }
};
