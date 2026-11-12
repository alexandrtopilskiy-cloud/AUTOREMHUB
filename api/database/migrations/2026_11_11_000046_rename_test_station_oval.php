<?php

declare(strict_types=1);

use App\Support\TestParticipantRequisites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тестовая СТО ООО «Овал» (логины STO3–STO5) становится ИП Топильский АВ:
 * новое название, реквизиты, адреса, телефон и полный спектр услуг в
 * карточке СТО. Логины, склад, документы и ЗР остаются за той же
 * организацией. Если СТО «Овал» нет — ничего не делает.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            TestParticipantRequisites::renameStation((int) $tenantId, 'ООО "Овал"', 'ИП Топильский АВ');
        }
    }

    public function down(): void
    {
        // Данные не откатываются: реквизиты могли быть уже исправлены вручную.
    }
};
