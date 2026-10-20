<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Edo\EdoProviderInterface;
use App\Services\Edo\EdoManager;
use App\Services\Edo\Providers\SberKorus\SberKorusClient;
use App\Services\Edo\Providers\SberKorus\SberKorusMapper;
use Illuminate\Support\ServiceProvider;

final class EdoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/edo.php', 'edo');

        $this->app->singleton(EdoManager::class);
        $this->app->singleton(SberKorusMapper::class);

        $this->app->bind(SberKorusClient::class, function ($app): SberKorusClient {
            $config = config('edo.providers.sberkorus');

            return new SberKorusClient(
                baseUrl: (string) $config['base_url'],
                username: $config['username'] !== null ? (string) $config['username'] : null,
                password: $config['password'] !== null ? (string) $config['password'] : null,
                timeout: (int) ($config['http']['timeout'] ?? 30),
                connectTimeout: (int) ($config['http']['connect_timeout'] ?? 10),
            );
        });

        $this->app->bind(EdoProviderInterface::class, static function ($app): EdoProviderInterface {
            return $app->make(EdoManager::class)->provider();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/edo.php' => config_path('edo.php'),
        ], 'edo-config');
    }
}