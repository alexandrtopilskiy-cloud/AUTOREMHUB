<?php

declare(strict_types=1);

use App\Support\RussianPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Телефоны во всех справочниках и документах хранятся в формате
 * «+7 ххх ххх хх хх» (ТЗ Alex от 10.11.2026). Ввод принимается в любом
 * равнозначном формате, см. App\Support\RussianPhone.
 */
return new class () extends Migration {
    private const TABLES = [
        'users' => ['users_phone_check', true],
        'vehicle_contacts' => ['vehicle_contacts_phone', false],
        'organization_people' => ['organization_people_phone', false],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$constraint, $nullable]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN phone TYPE varchar(16)");
            DB::statement("UPDATE {$table} SET phone = regexp_replace(phone, '^\\+7([0-9]{3})([0-9]{3})([0-9]{2})([0-9]{2})$', '+7 \\1 \\2 \\3 \\4') WHERE phone ~ '^\\+7[0-9]{10}$'");
            $check = $nullable ? "phone IS NULL OR phone ~ '" . RussianPhone::SQL_PATTERN . "'" : "phone ~ '" . RussianPhone::SQL_PATTERN . "'";
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$check})");
        }

        foreach (DB::table('organizations')->where('phone', '!=', '')->get(['id', 'phone']) as $row) {
            $phone = RussianPhone::normalize((string) $row->phone);
            if ($phone !== null && $phone !== $row->phone) {
                DB::table('organizations')->where('id', $row->id)->update(['phone' => $phone]);
            }
        }

        foreach (DB::table('suppliers')->whereNotNull('contacts')->get(['id', 'contacts']) as $row) {
            $contacts = json_decode((string) $row->contacts, true);
            if (!is_array($contacts)) {
                continue;
            }
            $changed = false;
            foreach ($contacts as &$contact) {
                $phone = is_array($contact) ? RussianPhone::normalize((string) ($contact['phone'] ?? '')) : null;
                if ($phone !== null && $phone !== $contact['phone']) {
                    $contact['phone'] = $phone;
                    $changed = true;
                }
            }
            unset($contact);
            if ($changed) {
                DB::table('suppliers')->where('id', $row->id)->update(['contacts' => json_encode($contacts, JSON_UNESCAPED_UNICODE)]);
            }
        }

        foreach (['contact_snapshot', 'driver_snapshot'] as $column) {
            DB::statement("UPDATE repair_requests SET {$column} = jsonb_set({$column}, '{phone}', to_jsonb(regexp_replace({$column}->>'phone', '^\\+7([0-9]{3})([0-9]{3})([0-9]{2})([0-9]{2})$', '+7 \\1 \\2 \\3 \\4'))) WHERE {$column} ->> 'phone' ~ '^\\+7[0-9]{10}$'");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => [$constraint, $nullable]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement("UPDATE {$table} SET phone = replace(phone, ' ', '') WHERE phone IS NOT NULL");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN phone TYPE char(12)");
            $check = $nullable ? "phone IS NULL OR phone ~ '^\\+7[0-9]{10}$'" : "phone ~ '^\\+7[0-9]{10}$'";
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$check})");
        }
    }
};
