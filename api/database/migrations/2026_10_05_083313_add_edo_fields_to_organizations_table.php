<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Идентификатор клиента в ЭДО-операторе (Company.Id из СберКоруса)
            $table->string('edo_client_id', 64)->nullable()->after('vat_rate')->index();

            // Идентификатор участника ЭДО (Company.Code, e.g. "2BK-7713026678-62288")
            $table->string('edo_edi_code', 46)->nullable()->after('edo_client_id');

            // Какой оператор обслуживает эту организацию.
            // null → использовать EDO_PROVIDER из .env (для одной организации — самый частый случай).
            // Заполнено → закреплено за конкретным оператором (задел на мультипровайдерность).
            $table->string('edo_provider', 32)->nullable()->after('edo_edi_code');

            // Дата, когда организация была зарегистрирована в ЭДО.
            $table->timestampTz('edo_registered_at')->nullable()->after('edo_provider');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'edo_client_id',
                'edo_edi_code',
                'edo_provider',
                'edo_registered_at',
            ]);
        });
    }
};