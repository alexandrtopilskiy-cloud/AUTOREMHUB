<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('part_manufacturers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('normalized_name', 200);
            $table->boolean('active')->default(true);
            $table->string('status', 20)->default('approved');
            $table->string('proposed_name', 200)->nullable();
            $table->boolean('proposed_active')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'normalized_name']);
            $table->comment('App\\Models\\PartManufacturer; предложения СТО применяются только после акцепта УК');
        });
        Schema::table('catalog_parts', function (Blueprint $table): void {
            $table->foreignId('part_manufacturer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
        });
        Schema::table('request_notifications', function (Blueprint $table): void {
            $table->foreignId('part_manufacturer_id')->nullable()->constrained()->cascadeOnDelete();
        });
        $names = config('part_manufacturers');
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ($names as $name) {
                DB::table('part_manufacturers')->insert([
                    'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                    'name' => $name, 'normalized_name' => mb_strtolower($name),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        foreach (DB::table('catalog_parts')->whereNotNull('manufacturer')->get() as $part) {
            $name = trim($part->manufacturer);
            if ($name === '') {
                continue;
            }
            $normalized = mb_strtolower($name);
            $id = DB::table('part_manufacturers')->where('tenant_id', $part->tenant_id)->where('normalized_name', $normalized)->value('id');
            $id ??= DB::table('part_manufacturers')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $part->tenant_id, 'name' => $name, 'normalized_name' => $normalized, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('catalog_parts')->where('id', $part->id)->update(['part_manufacturer_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('request_notifications', fn (Blueprint $table) => $table->dropConstrainedForeignId('part_manufacturer_id'));
        Schema::table('catalog_parts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('part_manufacturer_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['purchase_price', 'sale_price']);
        });
        Schema::dropIfExists('part_manufacturers');
    }
};
