<?php

declare(strict_types=1);

use App\Support\TestParticipantRequisites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заполняет пустые реквизиты тестовых участников (УК, ООО «Альфа»,
 * «Бетта», «КУБ», «Овал») на уже развёрнутых серверах. Ручные значения
 * не перезаписываются; если тестовых организаций нет — ничего не делает.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            TestParticipantRequisites::apply((int) $tenantId);
        }
    }

    public function down(): void
    {
        // Данные не откатываются: реквизиты могли быть уже исправлены вручную.
    }
};
