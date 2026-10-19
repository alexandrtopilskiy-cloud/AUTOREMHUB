<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            foreach ($this->systemApplicabilities() as [$code, $name]) {
                $normalizedName = mb_strtolower($name);
                $existing = DB::table('time_norm_applicabilities')
                    ->where('tenant_id', $tenantId)
                    ->where('normalized_name', $normalizedName)
                    ->first();

                if ($existing === null) {
                    DB::table('time_norm_applicabilities')->insert([
                        'uuid'            => (string) Str::uuid(),
                        'tenant_id'       => $tenantId,
                        'code'            => $code,
                        'name'            => $name,
                        'normalized_name' => $normalizedName,
                        'is_system'       => true,
                        'active'          => true,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);

                    continue;
                }

                DB::table('time_norm_applicabilities')
                    ->where('id', $existing->id)
                    ->update([
                        'code'       => $code,
                        'name'       => $name,
                        'is_system'  => true,
                        'active'     => true,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Системные значения могли использоваться ранее, поэтому не удаляем их.
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function systemApplicabilities(): array
    {
        return [
            ['general', 'Общая'],
            ['special', 'Специальная'],
            ['vehicle', 'По авто'],
        ];
    }
};
