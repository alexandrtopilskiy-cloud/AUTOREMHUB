<?php

declare(strict_types=1);

namespace App\Providers\Edo;

use App\Enums\Edo\EdoProvider;
use App\Services\Edo\Providers\EdoRu\EdoRuClient;
use App\Services\Edo\Providers\EdoRu\EdoRuMapper;
use App\Services\Edo\Providers\EdoRu\EdoRuProvider;
use App\Services\Edo\Xml\Upd503Builder;
use App\Services\Edo\Xml\XsdValidator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Регистрирует сервисы ЭДО.ру (ЭДО.Поток) в контейнере.
 *
 * Конфиг берётся из config('edo.providers.edoru').
 */
final class EdoRuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EdoRuClient::class, function (Container $app): EdoRuClient {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('edo.providers.' . EdoProvider::EdoRu->value, []);

            return EdoRuClient::fromConfig($config);
        });

        $this->app->singleton(EdoRuMapper::class);

        $this->app->singleton(XsdValidator::class);

        $this->app->singleton(Upd503Builder::class, function (Container $app): Upd503Builder {
            return new Upd503Builder(
                validator: $app->make(XsdValidator::class),
                xsdPath:   base_path('resources/edo/xsd/ON_NSCHFDOPPR_1_997_01_05_03_05.xsd'),
            );
        });

        $this->app->singleton(EdoRuProvider::class, function (Container $app): EdoRuProvider {
            return new EdoRuProvider(
                client:     $app->make(EdoRuClient::class),
                mapper:     $app->make(EdoRuMapper::class),
                updBuilder: $app->make(Upd503Builder::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
