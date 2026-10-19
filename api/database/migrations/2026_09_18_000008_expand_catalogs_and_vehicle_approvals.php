<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE time_norms ALTER COLUMN hours DROP NOT NULL');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('short_name')->default('');
            $table->string('inn', 12)->default('');
            $table->string('kpp', 9)->default('');
            $table->string('ogrn', 15)->default('');
            $table->text('legal_address')->default('');
            $table->text('actual_address')->default('');
            $table->text('postal_address')->default('');
            $table->string('director_name')->default('');
            $table->string('phone', 30)->default('');
            $table->string('email')->default('');
            $table->string('bank_name')->default('');
            $table->string('bik', 9)->default('');
            $table->string('settlement_account', 20)->default('');
            $table->string('correspondent_account', 20)->default('');
            $table->string('tax_regime', 80)->default('');
            $table->decimal('vat_rate', 5, 2)->nullable();
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->decimal('labor_hour_rate', 14, 2)
                ->nullable()
                ->after('labor_rate_coefficient');
            $table->boolean('active')->default(true)->after('labor_hour_rate');
        });

        Schema::table('vehicle_contacts', function (Blueprint $table): void {
            $table->string('kind', 20)->default('contact')->after('vehicle_id');
            $table->index(['vehicle_id', 'kind', 'active']);
        });

        DB::statement(
            "ALTER TABLE vehicle_contacts ADD CONSTRAINT vehicle_contacts_kind "
            . "CHECK (kind IN ('contact', 'driver'))"
        );

        Schema::table('stations', function (Blueprint $table): void {
            $table->jsonb('profile')->default(DB::raw("'{}'::jsonb"));
        });

        Schema::table('request_works', function (Blueprint $table): void {
            $table->decimal('hourly_rate', 14, 2)->nullable()->after('price');
        });

        Schema::table('request_parts', function (Blueprint $table): void {
            $table->foreignId('catalog_part_id')
                ->nullable()
                ->after('repair_request_id')
                ->constrained('catalog_parts')
                ->restrictOnDelete();
            $table->string('unit', 30)->default('шт.')->after('article');
        });

        Schema::create('vehicle_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('status', 20)->default('pending');
            $table->jsonb('payload');
            $table->text('review_comment')->default('');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['organization_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE vehicle_change_requests ADD CONSTRAINT vehicle_change_action "
            . "CHECK (action IN ('create', 'update', 'delete'))"
        );
        DB::statement(
            "ALTER TABLE vehicle_change_requests ADD CONSTRAINT vehicle_change_status "
            . "CHECK (status IN ('pending', 'approved', 'rejected'))"
        );

        DB::statement(
            'ALTER TABLE request_notifications ALTER COLUMN repair_request_id DROP NOT NULL'
        );

        Schema::table('request_notifications', function (Blueprint $table): void {
            $table->foreignId('vehicle_change_request_id')
                ->nullable()
                ->after('repair_request_id')
                ->constrained('vehicle_change_requests')
                ->cascadeOnDelete();
            $table->unique(
                ['user_id', 'vehicle_change_request_id', 'kind'],
                'request_notifications_vehicle_change_unique',
            );
        });

        DB::statement(
            "COMMENT ON TABLE vehicle_change_requests IS "
            . "'App\\Models\\VehicleChangeRequest — изменения ТС Заказчика, "
            . "ожидающие акцепта УК'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicles.labor_hour_rate IS "
            . "'Стоимость нормо-часа в рублях; редактируется только УК'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicles.active IS "
            . "'Признак действующей записи; удаление справочника "
            . "выполняется логически'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_contacts.kind IS "
            . "'Тип связанного физического лица: contact или driver'"
        );
        DB::statement(
            "COMMENT ON COLUMN stations.profile IS "
            . "'Структурированный чек-лист возможностей "
            . "и ограничений СТО'"
        );
        DB::statement(
            "COMMENT ON COLUMN request_works.hourly_rate IS "
            . "'Снимок стоимости нормо-часа автомобиля "
            . "на момент расчёта'"
        );
        DB::statement(
            "COMMENT ON COLUMN request_parts.catalog_part_id IS "
            . "'Неизменяемая запись справочника запчастей УК'"
        );
        DB::statement(
            "COMMENT ON COLUMN time_norms.hours IS "
            . "'Норма времени; NULL означает, что значение "
            . "будет заполнено УК вручную'"
        );
    }

    public function down(): void
    {
        DB::statement('UPDATE time_norms SET hours = 0 WHERE hours IS NULL');
        DB::statement('ALTER TABLE time_norms ALTER COLUMN hours SET NOT NULL');

        Schema::table('request_notifications', function (Blueprint $table): void {
            $table->dropUnique('request_notifications_vehicle_change_unique');
            $table->dropConstrainedForeignId('vehicle_change_request_id');
        });

        DB::statement(
            'DELETE FROM request_notifications WHERE repair_request_id IS NULL'
        );
        DB::statement(
            'ALTER TABLE request_notifications ALTER COLUMN repair_request_id SET NOT NULL'
        );

        Schema::dropIfExists('vehicle_change_requests');

        Schema::table('request_parts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_part_id');
            $table->dropColumn('unit');
        });

        Schema::table('request_works', function (Blueprint $table): void {
            $table->dropColumn('hourly_rate');
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn('profile');
        });

        DB::statement(
            'ALTER TABLE vehicle_contacts DROP CONSTRAINT IF EXISTS vehicle_contacts_kind'
        );
        Schema::table('vehicle_contacts', function (Blueprint $table): void {
            $table->dropIndex(['vehicle_id', 'kind', 'active']);
            $table->dropColumn('kind');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['labor_hour_rate', 'active']);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'short_name',
                'inn',
                'kpp',
                'ogrn',
                'legal_address',
                'actual_address',
                'postal_address',
                'director_name',
                'phone',
                'email',
                'bank_name',
                'bik',
                'settlement_account',
                'correspondent_account',
                'tax_regime',
                'vat_rate',
            ]);
        });
    }
};
