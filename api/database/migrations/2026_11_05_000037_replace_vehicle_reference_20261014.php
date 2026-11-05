<?php

declare(strict_types=1);

use App\Services\VehicleReferenceImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Полная замена справочника марок и моделей ТС на «Справочник ТС от 14.10.2026»
 * (database/data/vehicle_reference_20261014.xlsx, решение от 05.11.2026).
 *
 * Импорт в режиме замены: все записи файла активны, марки и модели, которых
 * нет в файле, деактивируются (в т.ч. добавленные вручную). Строки не удаляются,
 * поэтому существующие ТС и ЗР сохраняют свои ссылки и продолжают открываться;
 * для новых ТС такие варианты больше не предлагаются.
 *
 * На пустой базе миграция ничего не делает: справочник загружает
 * `server-setup.sh init` (sto:vehicle-reference-import).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || !DB::table('vehicle_models')->exists()) {
            return;
        }

        app(VehicleReferenceImporter::class)->import(
            database_path('data/vehicle_reference_20261014.xlsx'),
            false,
            true,
            true,
        );
    }

    public function down(): void
    {
        // Предыдущий набор активных записей не восстанавливается.
    }
};
