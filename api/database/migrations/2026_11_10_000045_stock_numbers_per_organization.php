<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Номера Приходов и Реализаций — свой счётчик у каждой организации (УК и
 * каждая СТО ведут свой складской учёт). Уже выданные номера уникальны и
 * внутри организации, поэтому не меняются. Там, где есть тестовые логины,
 * добавляются логины второй СТО (ООО «Овал»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'type', 'sequence']);
            $table->unique(['organization_id', 'type', 'sequence']);
        });

        $testUser = User::query()->where('login', 'STO')->with('organization')->first();
        if ($testUser?->organization !== null) {
            Artisan::call('sto:test-users-create', ['--tenant' => $testUser->organization->tenant_id]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'type', 'sequence']);
            $table->unique(['tenant_id', 'type', 'sequence']);
        });
    }
};
