<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RepairStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Версия справочников для «изменений с прошлого раза».
 *
 * Кабинет раз в минуту и после операций перезапрашивал GET /catalogs и
 * GET /reference-data целиком: сотни ТС, норм, ЗЧ и т. п. с N+1 по истории ТС.
 * Теперь он присылает версию, полученную в прошлый раз, и если она не
 * изменилась, сервер отвечает коротким {unchanged: true}, не собирая записи.
 *
 * Версия — хэш числа строк и суммы xmin (номер транзакции, записавшей версию
 * строки; меняется при любом UPDATE, даже в ту же секунду и без updated_at)
 * таблиц, из которых собираются справочники, плюс число закрытых ЗР (от них
 * зависит последнее ТО в карточке ТС) и пользователь. Любое добавление,
 * изменение или удаление строки меняет версию; агрегат по таблицам — миллисекунды
 * против сборки всех записей.
 */
final class CatalogVersionService
{
    /** Таблицы, из которых собираются /catalogs и /reference-data. */
    public const TABLES = [
        'catalog_parts',
        'maintenance_regulation_items',
        'maintenance_regulations',
        'organization_people',
        'organizations',
        'part_manufacturers',
        'service_reasons',
        'stations',
        'suppliers',
        'time_norm_applicabilities',
        'time_norms',
        'users',
        'vehicle_change_requests',
        'vehicle_contacts',
        'vehicle_drive_types',
        'vehicle_engine_variants',
        'vehicle_generations',
        'vehicle_makes',
        'vehicle_models',
        'vehicle_service_records',
        'vehicle_transmission_types',
        'vehicles',
        'work_types',
    ];

    public function version(User $user): string
    {
        $parts = array_map(
            fn (string $table) => "SELECT '{$table}' AS t, COUNT(*)::text AS n, "
                . "COALESCE(SUM(xmin::text::bigint), 0)::text AS x FROM {$table}",
            self::TABLES,
        );
        $parts[] = "SELECT 'closed_requests' AS t, COUNT(*)::text AS n, '0' AS x FROM repair_requests WHERE stage = "
            . (int) RepairStage::Closed->value;

        $rows = DB::select(implode(' UNION ALL ', $parts));

        $state = [$user->id, $user->role->value, $user->organization_id];
        foreach ($rows as $row) {
            $state[] = $row->t . ':' . $row->n . ':' . $row->x;
        }

        return substr(hash('sha256', implode('|', $state)), 0, 32);
    }
}
