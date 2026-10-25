<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Edo\EdoProviderInterface;
use App\Events\RepairRequests\DiagnosticReportSaved;
use App\Events\RepairRequests\InvoiceIssued;
use App\Events\RepairRequests\ReadyForIssue;
use App\Events\RepairRequests\RepairRequestWaitingVehicle;
use App\Events\RepairRequests\WorkOrderCompleted;
use App\Listeners\RepairRequests\SendEstimateToEdo;
use App\Listeners\RepairRequests\SendPowerOfAttorneyOnWaitingVehicle;
use App\Listeners\RepairRequests\SendAgentReportToEdo;
use App\Listeners\RepairRequests\SendInvoiceToEdo;
use App\Listeners\RepairRequests\SendWorkOrderActToEdo;
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
            SendWorkOrderActToEdo::class
        );
        Event::listen(
            DiagnosticReportSaved::class,
            SendEstimateToEdo::class
        );
        Event::listen(
            RepairRequestWaitingVehicle::class,
            SendPowerOfAttorneyOnWaitingVehicle::class
        );

        Event::listen(
            ReadyForIssue::class,
            SendAgentReportToEdo::class
        );

        Event::listen(
            InvoiceIssued::class,
            SendInvoiceToEdo::class
        );
    }
}
