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
        $this->createVehicleApplicabilityCatalogs();
        $this->createImportBatches();
        $this->extendVehicles();
        $this->extendWorkTypes();
        $this->extendTimeNorms();
        $this->extendRequestWorks();
        $this->addComments();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE request_works DROP CONSTRAINT IF EXISTS request_works_catalog_amounts');
        DB::statement('ALTER TABLE time_norms DROP CONSTRAINT IF EXISTS time_norms_positive_hours');
        DB::statement('ALTER TABLE work_types DROP CONSTRAINT IF EXISTS work_types_code_format');
        DB::statement('ALTER TABLE work_types DROP CONSTRAINT IF EXISTS work_types_group_code_format');
        DB::statement('DROP INDEX IF EXISTS time_norms_active_norm_id_unique');
        DB::statement('DROP INDEX IF EXISTS time_norms_version_norm_id_unique');
        DB::statement('DROP INDEX IF EXISTS time_norms_selection_idx');
        DB::statement('DROP INDEX IF EXISTS work_types_active_code_unique');
        DB::statement('DROP INDEX IF EXISTS work_types_version_code_unique');

        Schema::table('request_works', function (Blueprint $table): void {
            $table->dropColumn([
                'work_code_snapshot',
                'norm_id_snapshot',
                'norm_selection_scope',
                'norm_override_reason',
                'norm_is_manual',
                'line_amount',
            ]);
        });
        DB::table('request_works')->whereNull('price')->update(['price' => 0]);
        DB::statement('ALTER TABLE request_works ALTER COLUMN price SET NOT NULL');

        DB::table('time_norms')->whereNull('hours')->update(['hours' => 0]);
        DB::statement('ALTER TABLE time_norms ALTER COLUMN hours SET NOT NULL');

        Schema::table('time_norms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_import_id');
            $table->dropConstrainedForeignId('vehicle_drive_type_id');
            $table->dropConstrainedForeignId('vehicle_transmission_type_id');
            $table->dropConstrainedForeignId('vehicle_engine_variant_id');
            $table->dropConstrainedForeignId('vehicle_generation_id');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn([
                'norm_id',
                'modification_code',
                'active',
                'catalog_version',
                'archived_at',
            ]);
        });

        Schema::table('work_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_import_id');
            $table->dropColumn([
                'code',
                'group_code',
                'catalog_version',
                'source_row',
                'source_name',
                'archived_at',
            ]);
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_drive_type_id');
            $table->dropConstrainedForeignId('vehicle_transmission_type_id');
            $table->dropConstrainedForeignId('vehicle_engine_variant_id');
            $table->dropConstrainedForeignId('vehicle_generation_id');
            $table->dropColumn('modification_code');
        });

        Schema::dropIfExists('work_catalog_imports');
        Schema::dropIfExists('vehicle_engine_variants');
        Schema::dropIfExists('vehicle_generations');
        Schema::dropIfExists('vehicle_transmission_types');
        Schema::dropIfExists('vehicle_drive_types');
    }

    private function createVehicleApplicabilityCatalogs(): void
    {
        Schema::create('vehicle_generations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('normalized_name', 150);
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['vehicle_model_id', 'normalized_name']);
        });

        Schema::create('vehicle_engine_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_generation_id')
                ->nullable()
                ->constrained('vehicle_generations')
                ->restrictOnDelete();
            $table->string('code', 100);
            $table->string('engine_type', 30)->nullable();
            $table->decimal('power_hp', 6, 1)->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['vehicle_model_id', 'code']);
        });

        Schema::create('vehicle_transmission_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('vehicle_drive_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE vehicle_generations ADD CONSTRAINT vehicle_generations_years '
            . 'CHECK ((year_from IS NULL OR year_from BETWEEN 1900 AND 2100) '
            . 'AND (year_to IS NULL OR year_to BETWEEN 1900 AND 2100) '
            . 'AND (year_from IS NULL OR year_to IS NULL OR year_to >= year_from))'
        );
        DB::statement(
            'ALTER TABLE vehicle_engine_variants ADD CONSTRAINT vehicle_engine_variants_power '
            . 'CHECK (power_hp IS NULL OR power_hp BETWEEN 0.1 AND 3000.0)'
        );
    }

    private function createImportBatches(): void
    {
        Schema::create('work_catalog_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->char('checksum', 64);
            $table->string('catalog_version', 80);
            $table->string('status', 20);
            $table->jsonb('summary')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('errors')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'checksum']);
            $table->index(['tenant_id', 'status']);
        });
    }

    private function extendVehicles(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignId('vehicle_generation_id')
                ->nullable()
                ->after('vehicle_model_id')
                ->constrained('vehicle_generations')
                ->restrictOnDelete();
            $table->foreignId('vehicle_engine_variant_id')
                ->nullable()
                ->after('vehicle_generation_id')
                ->constrained('vehicle_engine_variants')
                ->restrictOnDelete();
            $table->foreignId('vehicle_transmission_type_id')
                ->nullable()
                ->after('vehicle_engine_variant_id')
                ->constrained('vehicle_transmission_types')
                ->restrictOnDelete();
            $table->foreignId('vehicle_drive_type_id')
                ->nullable()
                ->after('vehicle_transmission_type_id')
                ->constrained('vehicle_drive_types')
                ->restrictOnDelete();
            $table->string('modification_code', 100)
                ->nullable()
                ->after('vehicle_drive_type_id');
        });
    }

    private function extendWorkTypes(): void
    {
        Schema::table('work_types', function (Blueprint $table): void {
            $table->string('code', 8)->nullable()->after('tenant_id');
            $table->char('group_code', 2)->nullable()->after('code');
            $table->string('catalog_version', 80)->default('legacy')->after('group_name');
            $table->unsignedInteger('source_row')->nullable()->after('catalog_version');
            $table->string('source_name')->default('')->after('source_row');
            $table->foreignId('catalog_import_id')
                ->nullable()
                ->after('source_name')
                ->constrained('work_catalog_imports')
                ->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable()->after('active');
        });

        DB::statement(
            "WITH numbered AS ("
            . "SELECT id, row_number() OVER (PARTITION BY tenant_id ORDER BY id) AS number "
            . "FROM work_types) "
            . "UPDATE work_types AS work SET code = 'LG-' || lpad(numbered.number::text, 5, '0'), "
            . "group_code = 'LG' FROM numbered WHERE numbered.id = work.id"
        );
        DB::statement('ALTER TABLE work_types ALTER COLUMN code SET NOT NULL');
        DB::statement('ALTER TABLE work_types ALTER COLUMN group_code SET NOT NULL');
        DB::statement(
            'CREATE UNIQUE INDEX work_types_active_code_unique '
            . 'ON work_types (tenant_id, code) WHERE active'
        );
        DB::statement(
            'CREATE UNIQUE INDEX work_types_version_code_unique '
            . 'ON work_types (tenant_id, catalog_version, code)'
        );
        DB::statement(
            'ALTER TABLE work_types ADD CONSTRAINT work_types_code_format '
            . "CHECK (code ~ '^[A-Z]{2}-[0-9]{5}$')"
        );
        DB::statement(
            'ALTER TABLE work_types ADD CONSTRAINT work_types_group_code_format '
            . "CHECK (group_code ~ '^[A-Z]{2}$' AND left(code, 2) = group_code)"
        );
    }

    private function extendTimeNorms(): void
    {
        Schema::table('time_norms', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('uuid')->constrained()->restrictOnDelete();
            $table->string('norm_id', 20)->nullable()->after('tenant_id');
            $table->foreignId('vehicle_id')
                ->nullable()
                ->after('vehicle_model_id')
                ->constrained('vehicles')
                ->restrictOnDelete();
            $table->foreignId('vehicle_generation_id')
                ->nullable()
                ->after('vehicle_id')
                ->constrained('vehicle_generations')
                ->restrictOnDelete();
            $table->foreignId('vehicle_engine_variant_id')
                ->nullable()
                ->after('vehicle_generation_id')
                ->constrained('vehicle_engine_variants')
                ->restrictOnDelete();
            $table->foreignId('vehicle_transmission_type_id')
                ->nullable()
                ->after('vehicle_engine_variant_id')
                ->constrained('vehicle_transmission_types')
                ->restrictOnDelete();
            $table->foreignId('vehicle_drive_type_id')
                ->nullable()
                ->after('vehicle_transmission_type_id')
                ->constrained('vehicle_drive_types')
                ->restrictOnDelete();
            $table->string('modification_code', 100)->nullable()->after('vehicle_drive_type_id');
            $table->boolean('active')->default(true)->after('source');
            $table->string('catalog_version', 80)->default('legacy')->after('active');
            $table->foreignId('catalog_import_id')
                ->nullable()
                ->after('catalog_version')
                ->constrained('work_catalog_imports')
                ->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable()->after('catalog_import_id');
            $table->index(
                ['tenant_id', 'work_type_id', 'active'],
                'time_norms_selection_idx',
            );
        });

        DB::statement(
            'UPDATE time_norms AS norm SET tenant_id = work.tenant_id '
            . 'FROM work_types AS work WHERE work.id = norm.work_type_id'
        );
        DB::statement(
            "UPDATE time_norms SET norm_id = 'LN-' || lpad(id::text, 10, '0')"
        );
        DB::statement('ALTER TABLE time_norms ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE time_norms ALTER COLUMN norm_id SET NOT NULL');
        DB::statement('ALTER TABLE time_norms ALTER COLUMN hours DROP NOT NULL');
        DB::statement('UPDATE time_norms SET hours = NULL WHERE hours IS NOT NULL AND hours <= 0');
        DB::statement(
            'CREATE UNIQUE INDEX time_norms_active_norm_id_unique '
            . 'ON time_norms (tenant_id, norm_id) WHERE active'
        );
        DB::statement(
            'CREATE UNIQUE INDEX time_norms_version_norm_id_unique '
            . 'ON time_norms (tenant_id, catalog_version, norm_id)'
        );
        DB::statement(
            'ALTER TABLE time_norms ADD CONSTRAINT time_norms_positive_hours '
            . 'CHECK (hours IS NULL OR hours > 0)'
        );
        $this->seedVehicleAttributes();
        $this->backfillVehicleApplicabilityCatalogs();
    }

    private function extendRequestWorks(): void
    {
        Schema::table('request_works', function (Blueprint $table): void {
            $table->string('work_code_snapshot', 20)->default('')->after('name');
            $table->string('norm_id_snapshot', 30)->nullable()->after('time_norm_id');
            $table->string('norm_selection_scope', 40)->nullable()->after('norm_id_snapshot');
            $table->text('norm_override_reason')->nullable()->after('norm_selection_scope');
            $table->boolean('norm_is_manual')->default(false)->after('norm_override_reason');
            $table->decimal('line_amount', 16, 2)->nullable()->after('price');
        });

        DB::statement('ALTER TABLE request_works ALTER COLUMN price DROP NOT NULL');
        DB::statement(
            'UPDATE request_works AS line SET work_code_snapshot = COALESCE(work.code, \'\'), '
            . 'norm_id_snapshot = (SELECT norm.norm_id FROM time_norms AS norm '
            . 'WHERE norm.id = line.time_norm_id), '
            . 'line_amount = round(line.quantity * line.price, 2) '
            . 'FROM work_types AS work '
            . 'WHERE work.id = line.work_type_id'
        );
        DB::statement(
            'UPDATE request_works SET line_amount = round(quantity * price, 2) '
            . 'WHERE line_amount IS NULL AND price IS NOT NULL'
        );
        DB::statement(
            'ALTER TABLE request_works ADD CONSTRAINT request_works_catalog_amounts '
            . 'CHECK ((line_amount IS NULL OR line_amount >= 0) '
            . 'AND (norm_is_manual = false OR norm_override_reason IS NOT NULL))'
        );
    }

    private function seedVehicleAttributes(): void
    {
        $now = now();

        foreach ([
            ['manual', 'МКПП'],
            ['robot', 'РКПП'],
            ['automatic', 'АКПП'],
            ['cvt', 'CVT'],
            ['electric', 'электро'],
        ] as [$code, $name]) {
            DB::table('vehicle_transmission_types')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'code' => $code,
                'name' => $name,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            ['front', 'передний'],
            ['rear', 'задний'],
            ['all', 'полный'],
        ] as [$code, $name]) {
            DB::table('vehicle_drive_types')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'code' => $code,
                'name' => $name,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function backfillVehicleApplicabilityCatalogs(): void
    {
        DB::statement(
            "INSERT INTO vehicle_generations "
            . "(uuid, vehicle_model_id, name, normalized_name, year_from, "
            . "year_to, active, created_at, updated_at) "
            . "SELECT md5('vehicle-generation:' || model.id::text)::uuid, "
            . "model.id, btrim(model.body_code), "
            . "lower(btrim(regexp_replace(model.body_code, '\\s+', ' ', 'g'))), "
            . "model.model_year_from, model.model_year_to, true, "
            . "CURRENT_TIMESTAMP, CURRENT_TIMESTAMP "
            . "FROM vehicle_models AS model "
            . "WHERE btrim(model.body_code) <> '' "
            . "ON CONFLICT (vehicle_model_id, normalized_name) DO NOTHING"
        );

        DB::statement(
            "INSERT INTO vehicle_engine_variants "
            . "(uuid, vehicle_model_id, vehicle_generation_id, code, "
            . "engine_type, power_hp, fingerprint, active, created_at, updated_at) "
            . "SELECT md5('vehicle-engine:' || model.id::text)::uuid, "
            . "model.id, generation.id, btrim(model.engine_index), "
            . "model.engine_type, model.horsepower, model.fingerprint, true, "
            . "CURRENT_TIMESTAMP, CURRENT_TIMESTAMP "
            . "FROM vehicle_models AS model "
            . "LEFT JOIN vehicle_generations AS generation "
            . "ON generation.vehicle_model_id = model.id "
            . "AND generation.normalized_name = "
            . "lower(btrim(regexp_replace(model.body_code, '\\s+', ' ', 'g'))) "
            . "WHERE btrim(model.engine_index) <> '' "
            . "ON CONFLICT (fingerprint) DO NOTHING"
        );

        DB::statement(
            'UPDATE vehicles AS vehicle SET '
            . 'vehicle_generation_id = generation.id, '
            . 'vehicle_engine_variant_id = engine.id, '
            . 'vehicle_transmission_type_id = transmission.id, '
            . 'vehicle_drive_type_id = drive.id '
            . 'FROM vehicle_models AS model '
            . 'LEFT JOIN vehicle_generations AS generation '
            . 'ON generation.vehicle_model_id = model.id '
            . 'LEFT JOIN vehicle_engine_variants AS engine '
            . 'ON engine.vehicle_model_id = model.id '
            . 'LEFT JOIN vehicle_transmission_types AS transmission '
            . 'ON lower(transmission.name) = lower(model.transmission) '
            . 'LEFT JOIN vehicle_drive_types AS drive '
            . 'ON lower(drive.name) = lower(model.drive_type) '
            . 'WHERE vehicle.vehicle_model_id = model.id'
        );
    }

    private function addComments(): void
    {
        $comments = [
            'vehicle_generations' => 'App\\Models\\VehicleGeneration — '
                . 'поколения моделей автомобилей',
            'vehicle_engine_variants' => 'App\\Models\\VehicleEngineVariant — '
                . 'типизированные варианты двигателей',
            'vehicle_transmission_types' => 'App\\Models\\VehicleTransmissionType — '
                . 'закрытый справочник трансмиссий',
            'vehicle_drive_types' => 'App\\Models\\VehicleDriveType — '
                . 'закрытый справочник типов привода',
            'work_catalog_imports' => 'App\\Models\\WorkCatalogImport — '
                . 'атомарные импорты справочника работ',
        ];

        foreach ($comments as $table => $comment) {
            DB::statement('COMMENT ON TABLE ' . $table . ' IS ' . DB::getPdo()->quote($comment));
        }

        DB::statement("COMMENT ON COLUMN work_types.code IS 'Постоянный код работы AA-BBBBB'");
        DB::statement(
            "COMMENT ON COLUMN time_norms.norm_id IS "
            . "'Постоянный идентификатор отдельной нормы времени'"
        );
        DB::statement(
            "COMMENT ON COLUMN request_works.line_amount IS "
            . "'Зафиксированная сумма позиции: "
            . "норма × ставка × количество'"
        );
        DB::statement(
            "COMMENT ON COLUMN request_works.norm_override_reason IS "
            . "'Обоснование ручного изменения нормы только для заявки'"
        );
    }
};
