<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_people', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->char('phone', 12);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'kind', 'phone']);
            $table->index(['tenant_id', 'organization_id', 'kind', 'active']);
            $table->foreign(['organization_id', 'tenant_id'])
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE organization_people ADD CONSTRAINT organization_people_kind "
            . "CHECK (kind IN ('contact', 'driver'))"
        );
        DB::statement(
            'ALTER TABLE organization_people ADD CONSTRAINT organization_people_name '
            . 'CHECK (length(btrim(last_name)) > 0 AND length(btrim(first_name)) > 0)'
        );
        DB::statement(
            "ALTER TABLE organization_people ADD CONSTRAINT organization_people_phone "
            . "CHECK (phone ~ '^\\+7[0-9]{10}$')"
        );

        Schema::table('vehicle_contacts', function (Blueprint $table): void {
            $table->foreignId('organization_person_id')
                ->nullable()
                ->after('vehicle_id')
                ->constrained('organization_people')
                ->restrictOnDelete();
            $table->index(['organization_person_id', 'active']);
        });

        $contacts = DB::table('vehicle_contacts as contact')
            ->join('vehicles as vehicle', 'vehicle.id', '=', 'contact.vehicle_id')
            ->orderBy('contact.id')
            ->get([
                'contact.id',
                'contact.kind',
                'contact.last_name',
                'contact.first_name',
                'contact.middle_name',
                'contact.phone',
                'contact.active',
                'vehicle.tenant_id',
                'vehicle.organization_id',
            ]);

        foreach ($contacts as $contact) {
            $personId = DB::table('organization_people')
                ->where('organization_id', $contact->organization_id)
                ->where('kind', $contact->kind)
                ->where('phone', $contact->phone)
                ->value('id');

            if ($personId === null) {
                $personId = DB::table('organization_people')->insertGetId([
                    'uuid'            => (string) Str::uuid(),
                    'tenant_id'       => $contact->tenant_id,
                    'organization_id' => $contact->organization_id,
                    'kind'            => $contact->kind,
                    'last_name'       => $contact->last_name,
                    'first_name'      => $contact->first_name,
                    'middle_name'     => $contact->middle_name,
                    'phone'           => $contact->phone,
                    'active'          => $contact->active,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            DB::table('vehicle_contacts')
                ->where('id', $contact->id)
                ->update(['organization_person_id' => $personId]);
        }

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->foreignId('created_by')
                ->nullable()
                ->after('customer_organization_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedBigInteger('driver_id')->nullable()->after('contact_id');
            $table->jsonb('driver_snapshot')->nullable()->after('contact_snapshot');
            $table->index(['driver_id', 'vehicle_id']);

            $table->foreign(['driver_id', 'vehicle_id'])
                ->references(['id', 'vehicle_id'])
                ->on('vehicle_contacts')
                ->restrictOnDelete();
        });

        DB::statement(
            'UPDATE repair_requests AS request SET created_by = ('
            . 'SELECT event.actor_id FROM request_events AS event '
            . "WHERE event.repair_request_id = request.id AND event.action = 'create' "
            . 'ORDER BY event.id LIMIT 1) WHERE request.created_by IS NULL'
        );
        DB::statement(
            'ALTER TABLE repair_requests ADD CONSTRAINT repair_requests_driver_vehicle '
            . 'CHECK (driver_id IS NULL OR vehicle_id IS NOT NULL)'
        );
        DB::statement(
            'ALTER TABLE repair_requests ADD CONSTRAINT repair_requests_driver_snapshot '
            . "CHECK (driver_snapshot IS NULL OR jsonb_typeof(driver_snapshot) = 'object')"
        );

        Schema::create('document_locks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 30);
            $table->string('resource_key');
            $table->string('resource_label')->default('');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'resource_type', 'resource_key']);
            $table->index(['user_id', 'expires_at']);
            $table->index('expires_at');
        });

        DB::statement(
            "ALTER TABLE document_locks ADD CONSTRAINT document_locks_resource_type "
            . "CHECK (resource_type IN ('request', 'catalog_record', 'catalog_list'))"
        );

        DB::statement(
            "COMMENT ON TABLE organization_people IS "
            . "'App\\Models\\OrganizationPerson — справочник контактных лиц и водителей организации'"
        );
        DB::statement(
            "COMMENT ON COLUMN vehicle_contacts.organization_person_id IS "
            . "'Ссылка на организационный справочник; vehicle_contacts хранит привязку лица к ТС'"
        );
        DB::statement(
            "COMMENT ON COLUMN repair_requests.driver_id IS "
            . "'Выбранный водитель автомобиля в заявке'"
        );
        DB::statement(
            "COMMENT ON COLUMN repair_requests.driver_snapshot IS "
            . "'Снимок данных водителя на момент выбора'"
        );
        DB::statement(
            "COMMENT ON COLUMN repair_requests.created_by IS "
            . "'Пользователь, создавший черновик заявки'"
        );
        DB::statement(
            "COMMENT ON TABLE document_locks IS "
            . "'App\\Models\\DocumentLock — временная блокировка совместного открытия документа'"
        );

        DB::table('request_notifications')
            ->whereNotNull('read_at')
            ->whereIn(
                'user_id',
                DB::table('users')->select('id')->where('role', 'customer'),
            )
            ->delete();

        DB::table('request_notifications')
            ->where('kind', 'vehicle_catalog_approval')
            ->whereIn(
                'vehicle_change_request_id',
                DB::table('vehicle_change_requests')
                    ->select('id')
                    ->where('status', '!=', 'pending'),
            )
            ->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('document_locks');

        DB::statement(
            'ALTER TABLE repair_requests DROP CONSTRAINT IF EXISTS '
            . 'repair_requests_driver_snapshot'
        );
        DB::statement(
            'ALTER TABLE repair_requests DROP CONSTRAINT IF EXISTS '
            . 'repair_requests_driver_vehicle'
        );

        Schema::table('repair_requests', function (Blueprint $table): void {
            $table->dropForeign(['driver_id', 'vehicle_id']);
            $table->dropIndex(['driver_id', 'vehicle_id']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['driver_id', 'driver_snapshot']);
        });

        Schema::table('vehicle_contacts', function (Blueprint $table): void {
            $table->dropIndex(['organization_person_id', 'active']);
            $table->dropConstrainedForeignId('organization_person_id');
        });

        Schema::dropIfExists('organization_people');
    }
};
