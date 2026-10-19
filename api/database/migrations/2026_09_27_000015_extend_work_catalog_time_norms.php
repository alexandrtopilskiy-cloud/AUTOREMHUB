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
        Schema::create('time_norm_applicabilities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->default('custom');
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'normalized_name']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::table('work_types', function (Blueprint $table): void {
            $table->string('group_name')->default('Прочее')->after('name');
        });

        Schema::table('time_norms', function (Blueprint $table): void {
            $table->foreignId('applicability_id')
                ->nullable()
                ->after('work_type_id')
                ->constrained('time_norm_applicabilities')
                ->restrictOnDelete();
            $table->foreignId('vehicle_make_id')
                ->nullable()
                ->after('applicability_id')
                ->constrained('vehicle_makes')
                ->restrictOnDelete();
            $table->foreignId('vehicle_model_id')
                ->nullable()
                ->after('vehicle_make_id')
                ->constrained('vehicle_models')
                ->restrictOnDelete();
        });

        $now = now();

        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            $ids = [];

            foreach ($this->baseApplicabilities() as [$code, $name]) {
                $ids[$code] = DB::table('time_norm_applicabilities')->insertGetId([
                    'uuid'            => (string) Str::uuid(),
                    'tenant_id'       => $tenantId,
                    'code'            => $code,
                    'name'            => $name,
                    'normalized_name' => mb_strtolower($name),
                    'is_system'       => true,
                    'active'          => true,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }

            DB::table('time_norms')
                ->whereIn(
                    'work_type_id',
                    DB::table('work_types')->where('tenant_id', $tenantId)->select('id'),
                )
                ->whereRaw("btrim(vehicle_model) = ''")
                ->update(['applicability_id' => $ids['general']]);

            DB::table('time_norms')
                ->whereIn(
                    'work_type_id',
                    DB::table('work_types')->where('tenant_id', $tenantId)->select('id'),
                )
                ->whereRaw("btrim(vehicle_model) <> ''")
                ->update(['applicability_id' => $ids['special']]);
        }

        DB::statement(
            "ALTER TABLE work_types ADD CONSTRAINT work_types_group_name "
            . "CHECK (group_name IN ('Кузов', 'Салон', 'Двигатель', "
            . "'Трансмиссия', 'Шасси', 'Подвеска', 'Тормозная система', "
            . "'Рулевое управление', 'Техническое обслуживание', "
            . "'Регулировочные', 'Электрика', 'Шиномонтаж', 'Прочее'))"
        );

        DB::statement(
            "COMMENT ON TABLE time_norm_applicabilities IS "
            . "'App\\Models\\TimeNormApplicability — справочник "
            . "применимости норм времени'"
        );
        DB::statement(
            "COMMENT ON COLUMN work_types.group_name IS "
            . "'Закрытая функциональная группа работы'"
        );
        DB::statement(
            "COMMENT ON COLUMN time_norms.applicability_id IS "
            . "'Применимость нормы: общая, специальная, "
            . "по автомобилю или пользовательская'"
        );
        DB::statement(
            "COMMENT ON COLUMN time_norms.vehicle_make_id IS "
            . "'Ограничение нормы по марке для применимости По авто'"
        );
        DB::statement(
            "COMMENT ON COLUMN time_norms.vehicle_model_id IS "
            . "'Ограничение нормы по модели для применимости По авто'"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_types DROP CONSTRAINT IF EXISTS work_types_group_name');

        Schema::table('time_norms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_model_id');
            $table->dropConstrainedForeignId('vehicle_make_id');
            $table->dropConstrainedForeignId('applicability_id');
        });

        Schema::table('work_types', function (Blueprint $table): void {
            $table->dropColumn('group_name');
        });

        Schema::dropIfExists('time_norm_applicabilities');
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function baseApplicabilities(): array
    {
        return [
            ['general', 'Общая'],
            ['special', 'Специальная'],
            ['vehicle', 'По авто'],
        ];
    }
};
