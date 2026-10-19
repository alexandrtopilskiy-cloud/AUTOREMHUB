<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        Schema::create('work_order_versions',function(Blueprint $t):void{$t->id();$t->uuid('uuid')->unique();$t->foreignId('work_order_id')->constrained()->restrictOnDelete();$t->jsonb('snapshot');$t->timestampsTz();});
        DB::statement("COMMENT ON TABLE work_order_versions IS 'App\\Models\\WorkOrderVersion: предыдущие версии заказ-наряда при повторной дефектовке или ремонте'");
    }
    public function down(): void { Schema::dropIfExists('work_order_versions'); }
};
