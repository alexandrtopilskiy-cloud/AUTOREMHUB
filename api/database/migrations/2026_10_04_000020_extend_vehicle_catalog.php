<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->decimal('engine_volume_liters', 7, 3)->nullable()
                ->comment('VehicleModel: объём двигателя в литрах; NULL для электро');
        });
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->decimal('labor_hour_rate_official', 14, 2)->nullable();
            $table->decimal('labor_hour_rate_other', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['labor_hour_rate_official', 'labor_hour_rate_other']);
        });
        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->dropColumn('engine_volume_liters');
        });
    }
};
