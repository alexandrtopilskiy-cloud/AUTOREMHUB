<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->timestampsTz();
        });
        Schema::create('organizations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20); $t->string('name'); $t->timestampsTz();
            $t->unique(['id', 'tenant_id']);
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('login', 100)->unique(); $t->string('display_name');
            $t->string('role', 20); $t->string('password'); $t->boolean('active')->default(true);
            $t->rememberToken(); $t->timestampsTz();
        });
        Schema::create('catalog_records', function (Blueprint $t): void {
            $t->string('id', 80)->primary(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('organization_id'); $t->string('kind', 20); $t->jsonb('data'); $t->timestampsTz();
            $t->foreign(['organization_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('organizations')->restrictOnDelete();
            $t->unique(['id', 'tenant_id']); $t->index(['tenant_id', 'kind']);
        });
        Schema::create('repair_requests', function (Blueprint $t): void {
            $t->id(); $t->string('public_id', 36)->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('customer_organization_id'); $t->string('station_id', 80)->nullable();
            $t->foreign(['customer_organization_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('organizations')->restrictOnDelete();
            $t->foreign(['station_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('catalog_records')->restrictOnDelete();
            $t->unsignedBigInteger('revision')->default(0); $t->smallInteger('stage')->default(0);
            $t->jsonb('data'); $t->jsonb('works'); $t->jsonb('history'); $t->timestampsTz();
            $t->index(['tenant_id', 'customer_organization_id']); $t->index(['tenant_id', 'station_id', 'stage']);
        });
        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary(); $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable(); $t->text('user_agent')->nullable();
            $t->text('payload'); $t->integer('last_activity')->index();
        });
        Schema::create('cache', function (Blueprint $t): void {
            $t->string('key')->primary(); $t->mediumText('value'); $t->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $t): void {
            $t->string('key')->primary(); $t->string('owner'); $t->integer('expiration');
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_kind CHECK (kind IN ('customer','station','manager'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role CHECK (role IN ('customer','station','manager'))");
        DB::statement("ALTER TABLE catalog_records ADD CONSTRAINT catalog_kind CHECK (kind IN ('vehicles','stations','customers'))");
        DB::statement("ALTER TABLE repair_requests ADD CONSTRAINT request_stage CHECK (stage BETWEEN 0 AND 11), ADD CONSTRAINT request_revision CHECK (revision >= 0)");
        DB::statement("ALTER TABLE repair_requests ADD CONSTRAINT request_json CHECK (jsonb_typeof(data) = 'object' AND jsonb_typeof(works) = 'array' AND jsonb_typeof(history) = 'array')");
        DB::statement("CREATE UNIQUE INDEX catalog_one_organization_entry ON catalog_records (organization_id, kind) WHERE kind IN ('stations', 'customers')");
    }

    public function down(): void
    {
        foreach (['cache_locks', 'cache', 'sessions', 'repair_requests', 'catalog_records', 'users', 'organizations', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
