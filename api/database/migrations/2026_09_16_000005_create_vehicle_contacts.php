<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->char('phone', 12);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['id', 'vehicle_id']);
            $table->unique(['vehicle_id', 'phone']);
        });

        DB::statement(
            "ALTER TABLE vehicle_contacts ADD CONSTRAINT vehicle_contacts_name "
            . "CHECK (length(btrim(last_name)) > 0 AND length(btrim(first_name)) > 0)"
        );
        DB::statement(
            "ALTER TABLE vehicle_contacts ADD CONSTRAINT vehicle_contacts_phone "
            . "CHECK (phone ~ '^\\+7[0-9]{10}$')"
        );

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->jsonb('contact_snapshot')->nullable();
            $table->timestampTz('previous_service_at')->nullable();
            $table->index(['contact_id', 'vehicle_id']);

            $table->foreign(['contact_id', 'vehicle_id'])
                ->references(['id', 'vehicle_id'])
                ->on('vehicle_contacts')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE repair_requests ADD CONSTRAINT repair_requests_contact_vehicle '
            . 'CHECK (contact_id IS NULL OR vehicle_id IS NOT NULL)'
        );
        DB::statement(
            'ALTER TABLE repair_requests ADD CONSTRAINT repair_requests_contact_snapshot '
            . "CHECK (contact_snapshot IS NULL OR jsonb_typeof(contact_snapshot) = 'object')"
        );

        DB::statement(
            "COMMENT ON TABLE vehicle_contacts IS "
            . "'App\\Models\\VehicleContact — контактные лица, привязанные к автомобилю по vehicle_id; VIN хранится в vehicles'"
        );
        DB::statement("COMMENT ON COLUMN vehicle_contacts.id IS 'Глобальный целочисленный идентификатор контактного лица'");
        DB::statement("COMMENT ON COLUMN vehicle_contacts.uuid IS 'Внешний стабильный UUID контактного лица'");
        DB::statement("COMMENT ON COLUMN vehicle_contacts.vehicle_id IS 'Автомобиль контакта; VIN находится в vehicles.vin'");
        DB::statement("COMMENT ON COLUMN vehicle_contacts.phone IS 'Телефон РФ в формате +7XXXXXXXXXX'");
        DB::statement("COMMENT ON COLUMN repair_requests.contact_id IS 'Выбранное контактное лицо заявки'");
        DB::statement("COMMENT ON COLUMN repair_requests.contact_snapshot IS 'Снимок ФИО и телефона на момент выбора контакта'");
        DB::statement("COMMENT ON COLUMN repair_requests.previous_service_at IS 'Дата предыдущего зафиксированного пробега автомобиля'");

        DB::table('service_reasons')
            ->where('code', 'repair')
            ->update(['active' => false]);
        DB::table('service_reasons')
            ->where('code', 'maintenance')
            ->update(['name' => 'ТО']);
    }

    public function down(): void
    {
        DB::table('service_reasons')
            ->where('code', 'repair')
            ->update(['active' => true]);
        DB::table('service_reasons')
            ->where('code', 'maintenance')
            ->update(['name' => 'Техническое обслуживание']);

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->dropForeign(['contact_id', 'vehicle_id']);
            $table->dropColumn([
                'contact_id',
                'contact_snapshot',
                'previous_service_at',
            ]);
        });

        Schema::dropIfExists('vehicle_contacts');
    }
};
