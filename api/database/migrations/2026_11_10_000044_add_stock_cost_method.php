<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Метод списания ЗЧ со склада (ТЗ Alex от 10.11.2026): ФИФО, ЛИФО или
 * средневзвешенная цена. От него зависят цена ЗЧ, подставляемая в ЗР и
 * Реализацию, себестоимость списания и стоимость остатков.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_settings', function (Blueprint $table): void {
            $table->string('cost_method', 20)->default('average');
        });
        DB::statement("ALTER TABLE warehouse_settings ADD CONSTRAINT warehouse_settings_cost_method_check CHECK (cost_method IN ('fifo', 'lifo', 'average'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE warehouse_settings DROP CONSTRAINT IF EXISTS warehouse_settings_cost_method_check');
        Schema::table('warehouse_settings', function (Blueprint $table): void {
            $table->dropColumn('cost_method');
        });
    }
};
