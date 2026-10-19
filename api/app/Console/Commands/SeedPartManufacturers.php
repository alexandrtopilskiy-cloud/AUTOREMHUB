<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PartManufacturer;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class SeedPartManufacturers extends Command
{
    protected $signature = 'catalog:seed-part-manufacturers {--tenant= : ID управляющей компании}';
    protected $description = 'Добавить стартовых производителей ЗЧ без замены действующих записей';

    public function handle(): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q) => $q->whereKey($this->option('tenant')))->get() as $tenant) {
            foreach (config('part_manufacturers') as $name) {
                PartManufacturer::query()->firstOrCreate(['tenant_id' => $tenant->id, 'normalized_name' => mb_strtolower($name)], ['name' => $name]);
            }
        }
        $this->info('Стартовый список производителей добавлен');
        return self::SUCCESS;
    }
}
