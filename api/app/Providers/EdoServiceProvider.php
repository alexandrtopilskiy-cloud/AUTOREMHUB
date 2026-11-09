<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Edo\EdoProviderInterface;
use App\Events\RepairRequests\WorkOrderCompleted;
use App\Listeners\RepairRequests\GenerateEdoClosingDocuments;
use App\Services\Edo\EdoManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class EdoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/edo.php', 'edo');

        $this->app->singleton(EdoManager::class);

        $this->app->bind(EdoProviderInterface::class, static function ($app): EdoProviderInterface {
            return $app->make(EdoManager::class)->provider();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/edo.php' => config_path('edo.php'),
        ], 'edo-config');

        Event::listen(
            WorkOrderCompleted::class,
            GenerateEdoClosingDocuments::class,
        );
    }
}
