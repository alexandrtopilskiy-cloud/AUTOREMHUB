<?php

declare(strict_types=1);

namespace App\Console\Commands\Edo;

use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Jobs\PullEdoEventsJob;
use Illuminate\Console\Command;

final class PullEdoEventsCommand extends Command
{
    protected $signature = 'edo:pull-events
                            {--provider= : Явно указать провайдера (по умолчанию — EDO_PROVIDER)}';

    protected $description = 'Ставит в очередь вытягивание событий ЭДО';

    public function handle(): int
    {
        $providerValue = (string) ($this->option('provider') ?: config('edo.default'));

        if ($providerValue === '' || $providerValue === EdoProviderEnum::None->value) {
            $this->warn('Провайдер ЭДО не задан или отключён (EDO_PROVIDER=none)');

            return self::SUCCESS;
        }

        // Проверим, что значение валидно
        if (EdoProviderEnum::tryFrom($providerValue) === null) {
            $this->error(sprintf('Неизвестный провайдер: %s', $providerValue));

            return self::FAILURE;
        }

        PullEdoEventsJob::dispatch($providerValue);

        $this->info(sprintf('Queued pull-events for %s', $providerValue));

        return self::SUCCESS;
    }
}
