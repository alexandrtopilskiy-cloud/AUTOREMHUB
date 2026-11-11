<?php

declare(strict_types=1);

use App\Enums\OrganizationKind;
use App\Models\Warehouse;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * «ЗЧ от УК» (ТЗ Alex от 11.11.2026): ЗЧ ЗР продаёт УК со своего склада
 * (с наценкой УК, без агентской скидки). На этапе «Ожидание ТС на СТО»
 * СТО принимает ЗЧ — документ Перемещение (PRM-) списывает их со складов УК
 * и приходует на системный склад СТО «Склад от УК».
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            // Системный склад (from_manager — «Склад от УК»): создаётся
            // автоматически, не переименовывается и не удаляется.
            $table->string('system_code', 30)->nullable();
            $table->unique(['organization_id', 'system_code']);
        });

        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->foreignId('target_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('target_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('repair_request_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_documents DROP CONSTRAINT IF EXISTS stock_documents_type_check');
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_check CHECK (type IN ('receipt', 'sale', 'transfer'))");

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->boolean('parts_from_manager')->default(false);
            $table->foreignId('parts_transfer_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
        });

        // «Склад от УК» у каждой СТО. Склад с таким названием, если он уже
        // заведён вручную, становится системным.
        $stations = DB::table('organizations')->where('kind', OrganizationKind::Station->value)->get(['id', 'tenant_id']);
        foreach ($stations as $organization) {
            $existing = DB::table('warehouses')
                ->where('organization_id', $organization->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(Warehouse::FROM_MANAGER_NAME)])
                ->value('id');
            if ($existing !== null) {
                DB::table('warehouses')->where('id', $existing)->update(['system_code' => Warehouse::FROM_MANAGER]);
                continue;
            }
            DB::table('warehouses')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $organization->tenant_id,
                'organization_id' => $organization->id,
                'name' => Warehouse::FROM_MANAGER_NAME,
                'system_code' => Warehouse::FROM_MANAGER,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parts_transfer_document_id');
            $table->dropColumn('parts_from_manager');
        });
        DB::table('stock_movements')->where('source_type', 'transfer')->delete();
        DB::table('stock_document_items')->whereIn('stock_document_id', DB::table('stock_documents')->where('type', 'transfer')->select('id'))->delete();
        DB::table('stock_documents')->where('type', 'transfer')->delete();
        DB::statement('ALTER TABLE stock_documents DROP CONSTRAINT IF EXISTS stock_documents_type_check');
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_check CHECK (type IN ('receipt', 'sale'))");
        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('repair_request_id');
            $table->dropConstrainedForeignId('target_warehouse_id');
            $table->dropConstrainedForeignId('target_organization_id');
        });
        DB::table('warehouses')->where('system_code', 'from_manager')
            ->whereNotIn('id', DB::table('stock_movements')->select('warehouse_id'))
            ->whereNotIn('id', DB::table('stock_documents')->select('warehouse_id'))
            ->delete();
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'system_code']);
            $table->dropColumn('system_code');
        });
    }
};
