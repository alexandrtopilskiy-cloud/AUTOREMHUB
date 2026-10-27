<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Токен обновления (refresh_token), который выдается в Кабинете интегратора
            $table->string('edo_refresh_token', 2048)->nullable()->after('edo_token');

            // Время истечения access_token (для кэширования)
            $table->timestamp('edo_token_expires_at')->nullable()->after('edo_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['edo_refresh_token', 'edo_token_expires_at']);
        });
    }
};
