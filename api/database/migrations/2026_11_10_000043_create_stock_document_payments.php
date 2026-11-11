<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Оплаты по документам склада (ТЗ Alex от 10.11.2026): по Приходу —
 * оплата Поставщику, по Реализации — оплата от Заказчика. Документ может
 * оплачиваться частями; сумма оплат не превышает сумму документа.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('stock_document_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);
            $table->string('comment', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['stock_document_id', 'paid_on']);
        });
        DB::statement("ALTER TABLE stock_document_payments ADD CONSTRAINT stock_document_payments_amount_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE stock_document_payments ADD CONSTRAINT stock_document_payments_method_check CHECK (method IN ('cash', 'bank', 'card'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_document_payments');
    }
};
