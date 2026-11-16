<?php

declare(strict_types=1);

namespace App\Console\Commands\Edo;

use App\Enums\Edo\EdoProvider;
use App\Models\Organization;
use App\Services\Edo\Drivers\Sbis\SbisChangesPuller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Забирает события документооборота СБИС по всем организациям, подключённым
 * к СБИС (логин в «Документы ЭДО» → ⚙️): статусы у отправителя, входящие
 * документы и их статусы у получателя. Запускается планировщиком.
 */
final class SbisPullChangesCommand extends Command
{
    protected $signature = 'edo:sbis-changes {--organization= : Только эта организация (id)}';

    protected $description = 'Получает изменения документов из СБИС (СБИС.СписокИзменений)';

    public function handle(SbisChangesPuller $puller): int
    {
        if ((bool) config('edo.mock', true)) {
            $this->line('EDO_MOCK=true — СБИС не опрашивается');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->where('edo_provider', EdoProvider::Sbis->value)
            ->whereNotNull('edo_client_id')
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        foreach ($organizations as $organization) {
            try {
                $result = $puller->pull($organization);
                $this->line("{$organization->name}: документов {$result['documents']}, наших {$result['matched']}");
            } catch (Throwable $e) {
                Log::channel('edo')->error("SBIS: ошибка получения изменений организации {$organization->id}", ['error' => $e->getMessage()]);
                $this->error("{$organization->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
