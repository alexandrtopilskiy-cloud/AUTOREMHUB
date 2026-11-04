<?php

declare(strict_types=1);

use App\Support\TestParticipantRequisites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Дозаполняет реквизиты тестовых Заказчика ООО «Тест» и СТО «СТО-партнёр»
 * (и всех прочих тестовых участников, если что-то осталось пустым).
 * Ручные значения не перезаписываются.
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
    }
};
